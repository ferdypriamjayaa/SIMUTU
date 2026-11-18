<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\JotformForm;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jotform\JotformDefinition;
use App\Support\Jotform\JotformExport;
use App\Support\Jotform\JotformImporter;
use App\Support\Jotform\JotformImportException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Admin-only import of Jotform form definitions (never submissions) from
 * the export folder. Access is enforced by the `role:admin` route middleware.
 */
class JotformImportController extends Controller
{
    /** Categories an Admin may create indicators in (INM is a fixed national list). */
    private const CREATABLE_CATEGORIES = ['imp-rs', 'imp-unit'];

    public function index(): View
    {
        $forms = $this->forms();

        return view('jotform-import.index', [
            'forms' => $forms,
            'path' => JotformExport::path(),
            'counts' => $forms->countBy('state'),
            'fieldTotal' => $forms->sum(fn (array $form) => count($form['definition']?->fields ?? [])),
            'indicators' => Indicator::with('category')->orderBy('indicator_category_id')->orderBy('name')->get(),
            'takenIndicatorIds' => JotformForm::whereNotNull('indicator_id')->pluck('jotform_form_id', 'indicator_id'),
            'categories' => IndicatorCategory::whereIn('slug', self::CREATABLE_CATEGORIES)->orderBy('id')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
            'results' => session('jotformResults', []),
        ]);
    }

    /**
     * Confirm which indicator a form belongs to: an existing one, or a new
     * IMP indicator the Admin explicitly asks for.
     */
    public function map(Request $request, string $form): RedirectResponse
    {
        $definition = $this->definition($form);
        $bag = 'mapping'.$definition->formId;

        $validated = $request->validateWithBag($bag, [
            'target' => ['required', Rule::in(['existing', 'new'])],
            'indicator_id' => ['required_if:target,existing', 'nullable', 'integer', 'exists:indicators,id'],
            'category' => ['required_if:target,new', 'nullable', Rule::in(self::CREATABLE_CATEGORIES)],
            'name' => ['required_if:target,new', 'nullable', 'string', 'max:255'],
            'unit_id' => ['required_if:category,imp-unit', 'nullable', 'integer', Rule::exists('units', 'id')->where('is_active', true)],
        ], [
            'indicator_id.required_if' => 'Pilih indikator SIMUTU.',
            'category.required_if' => 'Pilih kategori indikator baru.',
            'name.required_if' => 'Nama indikator baru wajib diisi.',
            'unit_id.required_if' => 'Unit penanggung jawab wajib dipilih untuk indikator IMP Unit.',
        ], [
            'indicator_id' => 'Indikator',
            'category' => 'Kategori',
            'name' => 'Nama indikator',
            'unit_id' => 'Unit penanggung jawab',
        ]);

        try {
            $indicator = DB::transaction(function () use ($validated, $definition) {
                if ($validated['target'] === 'existing') {
                    $indicator = Indicator::findOrFail($validated['indicator_id']);
                } else {
                    $category = IndicatorCategory::where('slug', $validated['category'])->firstOrFail();
                    $name = trim($validated['name']);

                    // Same rule as the indicator master: a name is unique within its category.
                    if (Indicator::where('indicator_category_id', $category->id)->where('name', $name)->exists()) {
                        throw new JotformImportException("Indikator \"{$name}\" sudah ada di kategori {$category->name}. Pilih indikator tersebut pada daftar indikator yang sudah ada.");
                    }

                    $indicator = Indicator::create([
                        'indicator_category_id' => $category->id,
                        'unit_id' => $category->code === 'IMP_UNIT' ? $validated['unit_id'] : null,
                        'name' => $name,
                        'is_active' => true,
                    ]);
                }

                JotformImporter::map($definition, $indicator);

                return $indicator;
            });
        } catch (JotformImportException $exception) {
            return $this->back($definition)->withInput()->with('error', $exception->getMessage());
        }

        return $this->back($definition)->with('success', "\"{$definition->title}\" dipetakan ke indikator \"{$indicator->name}\".");
    }

    public function import(Request $request, string $form): RedirectResponse
    {
        $definition = $this->definition($form);
        $result = $this->run($definition, $request->user());

        return $this->back($definition)
            ->with($result['ok'] ? 'success' : 'error', $result['message'])
            ->with('jotformResults', [$definition->formId => $result]);
    }

    /**
     * Import every form that is mapped and valid. Each form runs in its own
     * transaction, so one failure never undoes or blocks the others.
     */
    public function importAll(Request $request): RedirectResponse
    {
        $results = [];

        foreach ($this->forms() as $form) {
            if (in_array($form['state'], ['ready', 'changed'], true)) {
                $results[$form['definition']->formId] = $this->run($form['definition'], $request->user());
            }
        }

        $imported = collect($results)->where('ok', true)->count();
        $failed = count($results) - $imported;

        $redirect = redirect()->route('jotform-import.index')->with('jotformResults', $results);

        if ($results === []) {
            return $redirect->with('error', 'Tidak ada formulir yang siap diimpor. Konfirmasi pemetaan indikator terlebih dahulu.');
        }

        return $failed === 0
            ? $redirect->with('success', "{$imported} formulir berhasil diimpor sebagai draf.")
            : $redirect->with('error', "{$imported} formulir berhasil diimpor, {$failed} gagal. Lihat keterangan pada tiap formulir.");
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function run(JotformDefinition $definition, User $user): array
    {
        try {
            // A title that matches an indicator exactly needs no manual mapping.
            if (! JotformForm::where('jotform_form_id', $definition->formId)->whereNotNull('indicator_id')->exists()) {
                $suggestion = JotformExport::suggest($definition, Indicator::all());

                if ($suggestion && $suggestion['exact']) {
                    JotformImporter::map($definition, $suggestion['indicator']);
                }
            }

            $result = JotformImporter::import($definition, $user);
            $version = $result['version'];

            return [
                'ok' => true,
                'message' => $result['status'] === JotformImporter::UNCHANGED
                    ? "\"{$definition->title}\" sudah diimpor (versi {$version->version}); tidak ada perubahan."
                    : "\"{$definition->title}\" diimpor sebagai draf versi {$version->version} dengan ".count($definition->fields).' field.',
            ];
        } catch (JotformImportException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => "\"{$definition->title}\" gagal diimpor karena kesalahan sistem; tidak ada data yang berubah."];
        }
    }

    /**
     * Every export on disk with its mapping and import state.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function forms(): Collection
    {
        $records = JotformForm::with(['indicator.category', 'formVersion'])->get()->keyBy('jotform_form_id');

        // Loaded with what the page needs to warn before a form in use gets a new version.
        $indicators = Indicator::with(['category', 'publishedFormVersion'])->withCount('observations')->get()->keyBy('id');

        $records->each(function (JotformForm $record) use ($indicators) {
            if ($record->indicator_id) {
                $record->setRelation('indicator', $indicators->get($record->indicator_id));
            }
        });

        return collect(JotformExport::all())->map(function (array $entry) use ($records, $indicators) {
            $definition = $entry['definition'];
            $record = $definition ? $records->get($definition->formId) : null;
            $suggestion = $definition && ! $record?->indicator ? JotformExport::suggest($definition, $indicators) : null;
            $mapped = $record?->indicator || ($suggestion['exact'] ?? false);

            $state = match (true) {
                $definition === null, ! $definition->isImportable() => 'error',
                $record?->formVersion && $record->definition_hash === $definition->hash => 'imported',
                $record?->formVersion !== null => 'changed',
                $mapped => 'ready',
                default => 'unmapped',
            };

            return [...$entry, 'record' => $record, 'suggestion' => $suggestion, 'state' => $state];
        });
    }

    private function definition(string $formId): JotformDefinition
    {
        return JotformExport::find($formId) ?? abort(404, 'Definisi formulir Jotform tidak ditemukan.');
    }

    private function back(JotformDefinition $definition): RedirectResponse
    {
        return redirect()->to(route('jotform-import.index').'#form-'.$definition->formId);
    }
}

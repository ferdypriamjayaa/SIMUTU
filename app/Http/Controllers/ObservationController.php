<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\DoctorPicker;
use App\Support\EmployeePicker;
use App\Support\ObservationAnswers;
use App\Support\ObservationReport;
use App\Support\ObservationWorkbook;
use App\Support\ReportFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ObservationController extends Controller
{
    public function __construct(
        private readonly EmployeePicker $employeePicker,
        private readonly DoctorPicker $doctorPicker,
    ) {}

    /**
     * PDF rendering is memory-bound; larger reports should use Excel.
     */
    private const PDF_ROW_LIMIT = 1000;

    /**
     * Observation report: one category, exactly one indicator, a date range,
     * one unit or every unit. Every answer of the indicator's form is its own
     * column (ObservationReport), so nothing has to be opened one by one.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Observation::class);

        $user = $request->user();
        $report = ReportFilters::fromRequest($request);
        $result = null;

        if ($report->indicator) {
            $query = $this->query($user, $report->scope());

            $result = [
                'layout' => new ObservationReport($report->indicator),
                'total' => (clone $query)->count(),
                'units' => (clone $query)->distinct()->count('unit_id'),
                'observations' => (clone $query)
                    ->withAnswers()
                    ->with(['unit', 'user'])
                    ->orderBy('observation_date')
                    ->orderBy('id')
                    ->paginate(25)
                    ->withQueryString(),
            ];
        }

        return view('observations.index', [
            'report' => $report,
            'result' => $result,
            'categories' => collect(ReportFilters::categoriesFor($user))->map(fn (array $c, string $slug) => [
                'label' => $c['label'],
                'count' => ReportFilters::indicators($user, $slug)->count(),
            ]),
            'indicators' => ReportFilters::indicators($user, $report->category),
            'units' => $user->canViewAllUnits() ? Unit::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function create(Request $request, ?Indicator $indicator = null): View
    {
        Gate::authorize('create', Observation::class);

        $unit = $request->user()->activeUnit();

        if ($indicator) {
            abort_unless(
                Indicator::observable()->whereKey($indicator->id)->exists(),
                404
            );

            abort_unless(
                $this->assignableIndicators($request->user(), $unit->id)->whereKey($indicator->id)->exists(),
                403,
                'Indikator ini tidak ditetapkan untuk unit Anda, atau kategorinya (INM/IMP) tidak termasuk izin Anda.'
            );
        }

        $version = $indicator?->publishedFormVersion()->with('fields.options')->first();
        $category = $this->categoryFilter($request);
        $all = $this->unitIndicators($request->user(), $unit->id);

        return view('observations.create', [
            'indicator' => $indicator,
            'version' => $version,
            'values' => $version ? ObservationAnswers::defaults($version) : [],
            'employees' => $version ? $this->employeePicker->forForm($indicator, $version, $unit) : [],
            'doctors' => $version ? $this->doctorPicker->forForm($indicator, $version, $unit) : [],
            'indicators' => $category === self::ALL_CATEGORIES ? $all : $all->filter(fn (Indicator $i) => $i->category?->slug === $category)->values(),
            'category' => $category,
            // Tabs of categories the user may record, plus "Semua".
            'categoryTabs' => collect(self::CATEGORY_TABS)
                ->filter(fn (string $label, string $slug) => $slug === self::ALL_CATEGORIES
                    || in_array(ReportFilters::CATEGORIES[$slug]['code'] ?? null, $request->user()->categoryCodes('create'), true))
                ->map(fn (string $label, string $slug) => [
                'label' => $label,
                'count' => $slug === self::ALL_CATEGORIES ? $all->count() : $all->where('category.slug', $slug)->count(),
            ]),
            'unit' => $unit,
        ]);
    }

    /** Category filter of the input page: query value => tab label. */
    private const CATEGORY_TABS = ['semua' => 'Semua', 'inm' => 'INM', 'imp-rs' => 'IMP RS', 'imp-unit' => 'IMP Unit'];

    private const ALL_CATEGORIES = 'semua';

    /**
     * The chosen category tab; anything unknown falls back to "Semua".
     */
    private function categoryFilter(Request $request): string
    {
        $value = (string) $request->query('kategori', self::ALL_CATEGORIES);

        return array_key_exists($value, self::CATEGORY_TABS) ? $value : self::ALL_CATEGORIES;
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Observation::class);

        $request->validate([
            'indicator_id' => [
                'required',
                'integer',
                Rule::in($this->assignableIndicators($request->user(), $request->user()->activeUnit()->id)->pluck('id')->all()),
            ],
        ], [
            'indicator_id.in' => 'Indikator tidak aktif, belum memiliki formulir terbit, atau tidak ditetapkan untuk unit Anda.',
        ], ['indicator_id' => 'Indikator']);

        $indicator = Indicator::findOrFail($request->integer('indicator_id'));
        $version = $indicator->publishedFormVersion()->with('fields.options')->firstOrFail();

        // The form was republished while this page was open: the answers no
        // longer match its fields, so the user fills in the current form.
        if ($request->filled('form_version_id') && $request->integer('form_version_id') !== $version->id) {
            return redirect()
                ->route('observations.create.indicator', [$indicator, ...$this->categoryQuery($request)])
                ->withInput()
                ->with('error', 'Formulir indikator ini baru saja diperbarui. Periksa kembali isian Anda pada formulir terbaru, lalu simpan ulang.');
        }

        // A chosen HRIS employee becomes the officer's name, as typed names are stored.
        $this->employeePicker->resolve($request, $indicator, $version, $request->user()->activeUnit());
        // Likewise a doctor chosen from the Doctor API becomes the doctor's name.
        $this->doctorPicker->resolve($request, $indicator, $version);

        $validated = $this->validateForm($request, $version);

        $user = $request->user();

        DB::transaction(function () use ($indicator, $version, $validated, $user) {
            // unit_id comes from the account, never from the submitted form.
            $observation = Observation::create([
                'indicator_id' => $indicator->id,
                'indicator_form_version_id' => $version->id,
                'unit_id' => $user->activeUnit()->id,
                'user_id' => $user->id,
                'observation_date' => $validated['observation_date'],
                ...ObservationAnswers::legacyAttributes($indicator, $version, $validated),
            ]);

            ObservationAnswers::sync($observation, $version, $validated);
        });

        // Back to the indicator list (modal closed, same category tab), ready for the next one.
        return redirect()
            ->route('observations.create', $this->categoryQuery($request))
            ->with('success', "Observasi \"{$indicator->name}\" berhasil disimpan.");
    }

    /**
     * The category tab the form was opened from, to return to it.
     *
     * @return array<string, string>
     */
    private function categoryQuery(Request $request): array
    {
        $value = (string) $request->input('kategori', '');

        return array_key_exists($value, self::CATEGORY_TABS) && $value !== self::ALL_CATEGORIES ? ['kategori' => $value] : [];
    }

    public function show(Observation $observation): View
    {
        Gate::authorize('view', $observation);

        $observation->load(['indicator.category', 'unit', 'user'])->loadMissing($this->answerRelations());

        return view('observations.show', [
            'observation' => $observation,
            'version' => ObservationAnswers::versionFor($observation),
            'values' => ObservationAnswers::display($observation),
        ]);
    }

    public function edit(Observation $observation): View
    {
        $observation->load(['indicator', 'unit', 'user'])->loadMissing($this->answerRelations());

        Gate::authorize('update', $observation);

        $version = ObservationAnswers::versionFor($observation);

        return view('observations.edit', [
            'observation' => $observation,
            'version' => $version,
            'values' => ObservationAnswers::raw($observation),
            // Employees of the observation's own unit, not the editor's.
            'employees' => $this->employeePicker->forForm($observation->indicator, $version, $observation->unit),
            'doctors' => $this->doctorPicker->forForm($observation->indicator, $version, $observation->unit),
        ]);
    }

    /**
     * Indicator, unit, author and form version stay fixed; only the recorded
     * values change. An observation is always edited on the form version it
     * was entered on, never on a newer one.
     */
    public function update(Request $request, Observation $observation): RedirectResponse
    {
        $observation->load(['indicator', 'unit'])->loadMissing($this->answerRelations());

        Gate::authorize('update', $observation);

        $version = ObservationAnswers::versionFor($observation);
        $this->employeePicker->resolve($request, $observation->indicator, $version, $observation->unit);
        $this->doctorPicker->resolve($request, $observation->indicator, $version);
        $validated = $this->validateForm($request, $version);

        DB::transaction(function () use ($observation, $version, $validated) {
            // Stored `data` keys that the form does not define are kept.
            $observation->update([
                'observation_date' => $validated['observation_date'],
                'indicator_form_version_id' => $version->id,
                ...ObservationAnswers::legacyAttributes($observation->indicator, $version, $validated, $observation->data),
            ]);

            ObservationAnswers::sync($observation, $version, $validated);
        });

        return redirect()
            ->route('observations.show', $observation)
            ->with('success', 'Observasi berhasil diperbarui.');
    }

    public function destroy(Observation $observation): RedirectResponse
    {
        $observation->load('indicator')->loadMissing($this->answerRelations());

        Gate::authorize('delete', $observation);

        $observation->delete();

        return redirect()
            ->route('observations.index')
            ->with('success', 'Observasi berhasil dihapus.');
    }

    /**
     * The report of one indicator as Excel or PDF. Every unit means every
     * observation of that same indicator; indicators are never combined.
     */
    public function export(Request $request, string $format): Response
    {
        Gate::authorize('export', Observation::class);

        $report = ReportFilters::fromRequest($request, complete: true);
        Gate::authorize('export', [Observation::class, $report->indicator]);
        $query = $this->query($request->user(), $report->scope());
        $filename = 'laporan-'.Str::slug(Str::limit($report->indicator->name, 60, '')).'-'.now()->format('Ymd-His');

        return $format === 'pdf'
            ? $this->pdf($query, $report, $filename)
            : $this->xlsx($query, $report, $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateForm(Request $request, IndicatorFormVersion $version): array
    {
        return $request->validate(
            [
                'observation_date' => ['required', 'date', 'before_or_equal:today'],
                ...ObservationAnswers::rules($version, $request->all()),
            ],
            [],
            [
                'observation_date' => 'Tanggal Observasi',
                ...ObservationAnswers::attributes($version),
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function answerRelations(): array
    {
        return ['values', 'formVersion.fields.options', 'indicator.baselineFormVersion.fields.options'];
    }

    /**
     * Active INM and IMP RS indicators mapped to the unit (indicator_unit)
     * with a published form: the ones it can record.
     */
    private function assignableIndicators(User $user, int $unitId): Builder
    {
        return Indicator::observable()->assignedTo($unitId)->creatableBy($user);
    }

    /**
     * Every active indicator mapped to the unit, of any category, each with
     * `input_status` for its card:
     *  - ready: INM / IMP RS with a published form, can be recorded;
     *  - unpublished: INM / IMP RS whose form is not published yet;
     *  - closed: a category not open for input (IMP Unit, see
     *    Indicator::INPUT_CATEGORIES). Shown, never recordable.
     *
     * @return \Illuminate\Support\Collection<int, Indicator>
     */
    private function unitIndicators(User $user, int $unitId): \Illuminate\Support\Collection
    {
        return Indicator::query()
            ->where('is_active', true)
            ->assignedTo($unitId)
            ->creatableBy($user)
            ->with(['category', 'publishedFormVersion:id,indicator_id,version'])
            ->orderBy('indicator_category_id')
            ->orderBy('name')
            ->get()
            ->each(fn (Indicator $indicator) => $indicator->setAttribute('input_status', match (true) {
                ! $indicator->isInputCategory() => 'closed',
                $indicator->publishedFormVersion === null => 'unpublished',
                default => 'ready',
            }));
    }

    private function query(User $user, array $filters): Builder
    {
        return Observation::query()->visibleTo($user)->filter($filters);
    }

    /**
     * The report as an .xlsx workbook (App\Support\ObservationWorkbook).
     * Built in a temporary file, sent, then removed.
     */
    private function xlsx(Builder $query, ReportFilters $report, string $filename): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'simutu-xlsx-');

        try {
            (new ObservationWorkbook($query, $report))->save($path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    /**
     * The report as PDF: one table, at most PDF_ROW_LIMIT observations.
     */
    private function pdf(Builder $query, ReportFilters $report, string $filename): Response
    {
        return Pdf::loadView('observations.pdf', [
            'report' => $report,
            'layout' => new ObservationReport($report->indicator),
            'observations' => (clone $query)->withAnswers()->with(['unit', 'user'])
                ->orderBy('observation_date')->orderBy('id')->limit(self::PDF_ROW_LIMIT)->get(),
            'total' => (clone $query)->count(),
            'units' => (clone $query)->distinct()->count('unit_id'),
            'limit' => self::PDF_ROW_LIMIT,
        ])
            ->setPaper('a4', 'landscape')
            ->download($filename.'.pdf');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveFormDraftRequest;
use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Support\FormBuilder;
use App\Support\ObservationAnswers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin-only management of indicator observation forms. Access is enforced
 * by the `role:admin` route middleware (and again in SaveFormDraftRequest).
 */
class FormBuilderController extends Controller
{
    /**
     * Versions of an indicator's form.
     */
    public function show(Indicator $indicator): View
    {
        $indicator->load('category');

        $versions = $indicator->formVersions()
            ->withCount(['observations', 'fields'])
            ->with(['creator:id,name', 'publisher:id,name'])
            ->get();

        // Records from before form versions are read through the first form.
        $legacyCount = $indicator->observations()->whereNull('indicator_form_version_id')->count();
        $baselineId = $indicator->baselineFormVersion()->value('indicator_form_versions.id');

        return view('form-builder.show', [
            'indicator' => $indicator,
            'versions' => $versions,
            'published' => $versions->firstWhere('status', IndicatorFormVersion::STATUS_PUBLISHED),
            'draft' => $versions->firstWhere('status', IndicatorFormVersion::STATUS_DRAFT),
            'legacyCount' => $legacyCount,
            'baselineId' => $baselineId,
        ]);
    }

    /**
     * Open the draft, creating it from the published form when there is none.
     * This is how a published form is "edited": always on a new version.
     */
    public function storeDraft(Request $request, Indicator $indicator): RedirectResponse
    {
        $draft = FormBuilder::draft($indicator, $request->user());

        return redirect()->route('form-builder.edit', [$indicator, $draft]);
    }

    public function edit(Indicator $indicator, IndicatorFormVersion $version): View|RedirectResponse
    {
        $this->ensureBelongs($indicator, $version);

        if (! $version->isDraft()) {
            return redirect()
                ->route('form-builder.show', $indicator)
                ->with('error', 'Versi yang sudah dipublikasikan tidak dapat diubah. Buat draf baru untuk mengubah formulir.');
        }

        $indicator->load('category');
        $version->load('fields.options');

        return view('form-builder.edit', [
            'indicator' => $indicator,
            'version' => $version,
            'fields' => $version->fields->map(fn (IndicatorField $field) => [
                ...$field->only([
                    'id', 'key', 'label', 'type', 'section', 'placeholder', 'help_text', 'is_required', 'is_active',
                    'show_in_summary', 'default_value', 'allow_decimal', 'min_length', 'max_length', 'format', 'max_field_key',
                    'visible_when_key', 'visible_when_values',
                ]),
                'min_value' => $field->min_value === null ? null : ObservationAnswers::number($field->min_value),
                'max_value' => $field->max_value === null ? null : ObservationAnswers::number($field->max_value),
                'options' => $field->options->map->only(['value', 'label'])->all(),
            ])->all(),
            'lockedKeys' => array_keys(FormBuilder::lockedFields($indicator)),
        ]);
    }

    /**
     * Save the draft; optionally continue to the preview or publish it.
     */
    public function update(SaveFormDraftRequest $request, Indicator $indicator, IndicatorFormVersion $version): RedirectResponse
    {
        FormBuilder::save($version, $request->fields());

        if ($request->intent() === 'publish') {
            FormBuilder::publish($version, $request->user());

            return redirect()
                ->route('form-builder.show', $indicator)
                ->with('success', "Formulir versi {$version->version} berhasil dipublikasikan dan kini digunakan untuk observasi baru.");
        }

        if ($request->intent() === 'preview') {
            return redirect()->route('form-builder.preview', [$indicator, $version]);
        }

        return redirect()
            ->route('form-builder.edit', [$indicator, $version])
            ->with('success', 'Draf formulir berhasil disimpan.');
    }

    /**
     * Read-only rendering of any version, exactly as PIC Unit sees the form.
     */
    public function preview(Indicator $indicator, IndicatorFormVersion $version): View
    {
        $this->ensureBelongs($indicator, $version);

        $version->load('fields.options');

        return view('form-builder.preview', [
            'indicator' => $indicator,
            'version' => $version,
            'values' => ObservationAnswers::defaults($version),
        ]);
    }

    public function publish(Request $request, Indicator $indicator, IndicatorFormVersion $version): RedirectResponse
    {
        $this->ensureBelongs($indicator, $version);

        FormBuilder::publish($version, $request->user());

        return redirect()
            ->route('form-builder.show', $indicator)
            ->with('success', "Formulir versi {$version->version} berhasil dipublikasikan dan kini digunakan untuk observasi baru.");
    }

    /**
     * Discard a draft.
     */
    public function destroy(Indicator $indicator, IndicatorFormVersion $version): RedirectResponse
    {
        $this->ensureBelongs($indicator, $version);

        FormBuilder::discard($version);

        return redirect()
            ->route('form-builder.show', $indicator)
            ->with('success', 'Draf formulir berhasil dihapus.');
    }

    private function ensureBelongs(Indicator $indicator, IndicatorFormVersion $version): void
    {
        abort_unless($version->indicator_id === $indicator->id, 404);
    }
}

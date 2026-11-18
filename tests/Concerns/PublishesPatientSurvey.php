<?php

namespace Tests\Concerns;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\IndicatorFormVersion;
use App\Models\User;
use App\Support\Jotform\JotformExport;
use App\Support\Jotform\JotformImporter;

/**
 * The patient satisfaction survey as it is set up in SIMUTU: imported from
 * its Jotform export into an INM indicator, with the service questions shown
 * only for their "Jenis Pelayanan", then published through the Form Builder.
 *
 * Needs BuildsIndicatorForms and an INM category.
 */
trait PublishesPatientSurvey
{
    protected const PATIENT_SURVEY_FORM = '213401282467047';

    /** external id => [external id of the field it depends on, option label] */
    protected const SURVEY_CONDITIONS = [
        '5' => ['4', 'LAINNYA'],
        '53' => ['6', 'Rawat Inap'], '8' => ['6', 'Rawat Inap'], '12' => ['6', 'Rawat Inap'],
        '11' => ['6', 'Rawat Jalan/Poliklinik'], '9' => ['6', 'Rawat Jalan/Poliklinik'],
        '13' => ['6', 'IGD'],
        '14' => ['6', 'Lainnya'], '15' => ['6', 'Lainnya'],
        '47' => ['46', 'YA'],
    ];

    protected function publishPatientSurvey(User $admin): IndicatorFormVersion
    {
        $inm = IndicatorCategory::where('code', 'INM')->sole();
        $indicator = Indicator::create(['indicator_category_id' => $inm->id, 'name' => 'Survei Kepuasan Pasien', 'is_active' => true]);

        $definition = JotformExport::find(self::PATIENT_SURVEY_FORM);
        JotformImporter::map($definition, $indicator);
        $draft = JotformImporter::import($definition, $admin)['version'];

        $byExternal = $draft->fields()->with('options')->get()->keyBy('external_id');
        $fields = $this->builderFields($draft);

        foreach ($fields as &$field) {
            $external = (string) $byExternal->firstWhere('key', $field['key'])->external_id;

            if (isset(self::SURVEY_CONDITIONS[$external])) {
                [$trigger, $label] = self::SURVEY_CONDITIONS[$external];
                $field['visible_when_key'] = $byExternal[$trigger]->key;
                $field['visible_when_values'] = [$byExternal[$trigger]->options->firstWhere('label', $label)->value];
                $field['is_required'] = $external !== '47';
            }

            // Short single choices as buttons, as in the reviewed draft.
            if (in_array($external, ['1', '3', '4', '6', '44', '46', '53'], true)) {
                $field = [...$field, 'type' => 'radio', 'placeholder' => null];
            }

            // Phone numbers keep their leading zero.
            if ($external === '50') {
                $field = [...$field, 'type' => 'text', 'format' => 'digits', 'max_length' => 20, 'min_value' => null, 'max_value' => null];
            }
        }
        unset($field);

        $this->saveDraft($admin, $draft, $fields, 'publish')->assertSessionHasNoErrors();

        return $indicator->publishedFormVersion()->with('fields.options')->firstOrFail();
    }
}

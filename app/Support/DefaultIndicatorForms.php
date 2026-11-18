<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use Illuminate\Support\Facades\DB;

/**
 * Installs an INM indicator's original form (ObservationForms) as published
 * version 1, with the same field keys, so existing data entry and existing
 * records keep working. From then on the form is edited in the form builder.
 */
class DefaultIndicatorForms
{
    /** Fields shown in the history list's "Ringkasan" column. */
    private const SUMMARY_KEYS = ['officer_name', 'patient_name', 'doctor_name'];

    public static function install(Indicator $indicator): ?IndicatorFormVersion
    {
        if (! ObservationForms::has($indicator->form_type) || $indicator->formVersions()->exists()) {
            return null;
        }

        return DB::transaction(function () use ($indicator) {
            $version = $indicator->formVersions()->create([
                'version' => 1,
                'status' => IndicatorFormVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $order = 0;

            foreach (ObservationForms::sections($indicator->form_type) as $section) {
                foreach ($section['fields'] as $key => $definition) {
                    $type = $definition['type'] === 'boolean' ? IndicatorField::TYPE_YES_NO : $definition['type'];
                    $rules = $definition['rules'] ?? [];
                    $isNumber = $type === IndicatorField::TYPE_NUMBER;
                    $lte = collect($rules)->first(fn ($rule) => str_starts_with($rule, 'lte:'));

                    $version->fields()->create([
                        'key' => $key,
                        'label' => $definition['label'],
                        'type' => $type,
                        'section' => $section['title'],
                        'is_required' => $definition['required'] ?? true,
                        'is_active' => true,
                        'show_in_summary' => in_array($key, self::SUMMARY_KEYS, true),
                        'sort_order' => $order++,
                        'min_value' => $isNumber ? 0 : null,
                        'max_value' => $isNumber ? 10000 : null,
                        'max_length' => $definition['max'] ?? null,
                        'format' => collect($rules)->contains(fn ($rule) => str_starts_with($rule, 'regex:')) ? 'code' : null,
                        'max_field_key' => $lte ? substr($lte, 4) : null,
                    ]);
                }
            }

            return $version;
        });
    }
}

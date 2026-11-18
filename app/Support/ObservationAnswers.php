<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Validates, stores and reads the answers of an observation against a form
 * version built in the form builder.
 *
 * Answers live in `observation_values`. Observations recorded before form
 * versions existed have no rows there; they are read from the original
 * observation columns / `data` JSON by field key, through the indicator's
 * first form version. For the four original INM forms those columns are also
 * kept up to date on every save, so nothing that reads them breaks.
 */
class ObservationAnswers
{
    /** Hard bounds for number fields without a configured minimum/maximum. */
    private const NUMBER_LIMIT = 999999999999;

    /**
     * The form an observation is read and edited with: the exact version it
     * was entered on, or the indicator's first form for older records.
     */
    public static function versionFor(Observation $observation): ?IndicatorFormVersion
    {
        return $observation->indicator_form_version_id
            ? $observation->formVersion
            : $observation->indicator?->baselineFormVersion;
    }

    /**
     * Server-side validation rules for every active field that applies to
     * the submitted answers. A conditional field whose condition is not met
     * (e.g. the Rawat Inap questions for an IGD patient) gets no rule at all,
     * so it is neither required nor validated, and it is left out of the
     * validated data: sync() never stores it.
     *
     * @param  array<string, mixed>  $answers  submitted input, keyed by field key
     * @return array<string, list<mixed>>
     */
    public static function rules(IndicatorFormVersion $version, array $answers = []): array
    {
        $fields = $version->activeFields()->filter(fn (IndicatorField $field) => $field->isVisibleFor($answers));
        $numberKeys = $fields->where('type', IndicatorField::TYPE_NUMBER)->pluck('key')->all();
        $rules = [];

        foreach ($fields as $field) {
            $presence = $field->is_required ? 'required' : 'nullable';
            $options = $field->isChoice() ? $field->options->pluck('value')->all() : [];

            switch ($field->type) {
                case IndicatorField::TYPE_NUMBER:
                    $rule = [
                        $presence,
                        $field->allow_decimal ? 'numeric' : 'integer',
                        'min:'.self::number($field->min_value ?? -self::NUMBER_LIMIT),
                        'max:'.self::number($field->max_value ?? self::NUMBER_LIMIT),
                    ];

                    if ($field->max_field_key !== null && $field->max_field_key !== $field->key
                        && in_array($field->max_field_key, $numberKeys, true)) {
                        $rule[] = 'lte:'.$field->max_field_key;
                    }

                    $rules[$field->key] = $rule;
                    break;

                case IndicatorField::TYPE_DATE:
                case IndicatorField::TYPE_TIME:
                case IndicatorField::TYPE_DATETIME:
                    $rules[$field->key] = [$presence, 'date_format:'.IndicatorField::TEMPORAL_FORMATS[$field->type]];
                    break;

                case IndicatorField::TYPE_YES_NO:
                    $rules[$field->key] = [$presence, Rule::in(['0', '1'])];
                    break;

                case IndicatorField::TYPE_SELECT:
                case IndicatorField::TYPE_RADIO:
                    $rules[$field->key] = [$presence, 'string', Rule::in($options)];
                    break;

                case IndicatorField::TYPE_CHECKBOX:
                    $rules[$field->key] = [$presence, 'array'];
                    $rules[$field->key.'.*'] = ['string', 'distinct', Rule::in($options)];
                    break;

                default:
                    $rule = [$presence, 'string', 'max:'.$field->maxLength()];

                    if ($field->min_length) {
                        $rule[] = 'min:'.$field->min_length;
                    }

                    if (isset(IndicatorField::FORMATS[$field->format])) {
                        $rule[] = 'regex:'.IndicatorField::FORMATS[$field->format]['pattern'];
                    }

                    $rules[$field->key] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Field labels used as validation attribute names.
     *
     * @return array<string, string>
     */
    public static function attributes(IndicatorFormVersion $version): array
    {
        $attributes = [];

        foreach ($version->activeFields() as $field) {
            $attributes[$field->key] = $field->label;

            if ($field->type === IndicatorField::TYPE_CHECKBOX) {
                $attributes[$field->key.'.*'] = $field->label;
            }
        }

        return $attributes;
    }

    /**
     * Initial values of a blank form (the configured defaults), keyed by field key.
     *
     * @return array<string, string|list<string>>
     */
    public static function defaults(IndicatorFormVersion $version): array
    {
        $defaults = [];

        foreach ($version->activeFields() as $field) {
            if ($field->default_value === null || $field->default_value === '') {
                continue;
            }

            $defaults[$field->key] = $field->type === IndicatorField::TYPE_CHECKBOX
                ? [$field->default_value]
                : $field->default_value;
        }

        return $defaults;
    }

    /**
     * Stored answers as form input values, keyed by field key: a string, or
     * a list of option values for checkbox fields.
     *
     * @return array<string, string|list<string>|null>
     */
    public static function raw(Observation $observation): array
    {
        $version = self::versionFor($observation);

        if ($version === null) {
            return [];
        }

        $raw = [];

        if ($observation->indicator_form_version_id) {
            $values = $observation->values->groupBy('indicator_field_id');

            foreach ($version->activeFields() as $field) {
                $stored = ($values[$field->id] ?? collect())->pluck('value');

                $raw[$field->key] = $field->type === IndicatorField::TYPE_CHECKBOX
                    ? $stored->filter(fn ($value) => $value !== null)->values()->all()
                    : $stored->first();
            }

            return $raw;
        }

        // Recorded before form versions: original columns and `data` JSON.
        foreach ($version->activeFields() as $field) {
            $value = in_array($field->key, ObservationForms::COLUMNS, true)
                ? $observation->getAttribute($field->key)
                : ($observation->data[$field->key] ?? null);

            $raw[$field->key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => array_map('strval', array_values($value)),
                default => (string) $value,
            };
        }

        return $raw;
    }

    /**
     * Human-readable answers in form order.
     *
     * @return list<array{key: string, label: string, type: string, value: string}>
     */
    public static function display(Observation $observation): array
    {
        $version = self::versionFor($observation);

        if ($version === null) {
            return [];
        }

        $raw = self::raw($observation);

        return $version->activeFields()
            ->map(fn (IndicatorField $field) => [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type,
                'value' => self::format($field, $raw[$field->key] ?? null),
            ])
            ->all();
    }

    /**
     * Short description for history lists, from the fields marked for it.
     */
    public static function summary(Observation $observation): string
    {
        // Recorded before form versions: the observed person and patient,
        // straight from the original columns, exactly as they were listed then.
        if (! $observation->indicator_form_version_id) {
            $parts = array_filter([
                $observation->officer_name ?? $observation->data['doctor_name'] ?? null,
                $observation->patient_name,
            ]);

            return $parts === [] ? '-' : implode(' · ', $parts);
        }

        $version = self::versionFor($observation);

        if ($version === null) {
            return '-';
        }

        $raw = self::raw($observation);

        $parts = $version->activeFields()
            ->where('show_in_summary', true)
            ->map(fn (IndicatorField $field) => self::format($field, $raw[$field->key] ?? null))
            ->reject(fn (string $value) => $value === '-')
            ->all();

        return $parts === [] ? '-' : implode(' · ', $parts);
    }

    public static function format(IndicatorField $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '-';
        }

        $label = fn (mixed $optionValue) => $field->options->firstWhere('value', (string) $optionValue)?->label
            ?? (string) $optionValue;

        switch ($field->type) {
            case IndicatorField::TYPE_YES_NO:
                return (string) $value === '1' ? 'Ya' : 'Tidak';

            case IndicatorField::TYPE_SELECT:
            case IndicatorField::TYPE_RADIO:
                return $label($value);

            case IndicatorField::TYPE_CHECKBOX:
                return implode(', ', array_map($label, (array) $value));

            case IndicatorField::TYPE_DATE:
            case IndicatorField::TYPE_DATETIME:
                try {
                    return Carbon::createFromFormat(IndicatorField::TEMPORAL_FORMATS[$field->type], (string) $value)
                        ->format($field->type === IndicatorField::TYPE_DATE ? 'd-m-Y' : 'd-m-Y H:i');
                } catch (Throwable) {
                    return (string) $value;
                }

            default:
                return (string) $value;
        }
    }

    /**
     * Replace the observation's answers with the validated input.
     * Call inside a transaction, together with saving the observation.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function sync(Observation $observation, IndicatorFormVersion $version, array $validated): void
    {
        $rows = [];
        $now = now();

        foreach ($version->activeFields() as $field) {
            $value = $validated[$field->key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $entries = $field->type === IndicatorField::TYPE_CHECKBOX ? array_values((array) $value) : [$value];

            foreach ($entries as $entry) {
                $rows[] = [
                    'observation_id' => $observation->id,
                    'indicator_field_id' => $field->id,
                    'indicator_field_option_id' => $field->isChoice()
                        ? $field->options->firstWhere('value', (string) $entry)?->id
                        : null,
                    'value' => $field->type === IndicatorField::TYPE_NUMBER
                        ? self::number($field->allow_decimal ? (float) $entry : (int) $entry)
                        : (string) $entry,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $observation->values()->delete();
        $observation->values()->insert($rows);
        $observation->unsetRelation('values');
    }

    /**
     * Values for the original observation columns and `data` JSON, for the
     * fields of the indicator's original INM form that the version still has.
     * Other `data` keys already stored are kept.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $existingData
     * @return array<string, mixed>
     */
    public static function legacyAttributes(Indicator $indicator, IndicatorFormVersion $version, array $validated, ?array $existingData = null): array
    {
        if (! ObservationForms::has($indicator->form_type)) {
            return [];
        }

        $fields = $version->activeFields()->keyBy('key');
        $attributes = [];
        $data = $existingData ?? [];

        foreach (ObservationForms::fields($indicator->form_type) as $key => $definition) {
            $field = $fields->get($key);

            if ($field === null || ! self::matchesLegacyType($definition['type'], $field)) {
                continue;
            }

            $value = $validated[$key] ?? null;
            $value = match (true) {
                $value === null || $value === '' => null,
                $definition['type'] === 'boolean' => (bool) (int) $value,
                $definition['type'] === 'number' => (int) $value,
                default => (string) $value,
            };

            if ($definition['storage'] === 'column') {
                $attributes[$key] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        $attributes['data'] = $data === [] ? null : $data;

        return $attributes;
    }

    private static function matchesLegacyType(string $legacyType, IndicatorField $field): bool
    {
        return match ($legacyType) {
            'boolean' => $field->type === IndicatorField::TYPE_YES_NO,
            'number' => $field->type === IndicatorField::TYPE_NUMBER && ! $field->allow_decimal,
            default => in_array($field->type, [
                IndicatorField::TYPE_TEXT,
                IndicatorField::TYPE_TEXTAREA,
                IndicatorField::TYPE_SELECT,
                IndicatorField::TYPE_RADIO,
                IndicatorField::TYPE_DATE,
                IndicatorField::TYPE_TIME,
                IndicatorField::TYPE_DATETIME,
            ], true),
        };
    }

    /**
     * A number without trailing zeros or exponent: 5, 2.5, -10.
     */
    public static function number(int|float $value): string
    {
        if (is_int($value) || floor($value) == $value) {
            return number_format($value, 0, '.', '');
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

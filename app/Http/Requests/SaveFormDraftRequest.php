<?php

namespace App\Http\Requests;

use App\Models\IndicatorField;
use App\Support\FormBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * The form builder submits the whole field list as one JSON document
 * (`payload`). It is decoded here and validated like any other input; the
 * controller only ever sees the normalised result of fields().
 */
class SaveFormDraftRequest extends FormRequest
{
    private const NUMBER_LIMIT = 999999999999;

    /** @var list<array<string, mixed>> */
    private array $normalized = [];

    public function authorize(): bool
    {
        if (! $this->user()?->isAdmin()) {
            return false;
        }

        $version = $this->route('version');

        abort_unless($version->indicator_id === $this->route('indicator')->id, 404);

        // Published and archived versions are immutable.
        abort_unless($version->isDraft(), 409, 'Versi formulir ini sudah dipublikasikan dan tidak dapat diubah. Buat draf baru untuk mengubah formulir.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        $decoded = json_decode((string) $this->input('payload', ''), true);

        $this->merge([
            'fields' => is_array($decoded) && is_array($decoded['fields'] ?? null) ? array_values($decoded['fields']) : null,
        ]);
    }

    public function rules(): array
    {
        $number = ['nullable', 'numeric', 'between:-'.self::NUMBER_LIMIT.','.self::NUMBER_LIMIT];

        return [
            'intent' => ['nullable', Rule::in(['save', 'preview', 'publish'])],
            'fields' => ['present', 'array', 'max:100'],
            'fields.*' => ['array'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', 'string', Rule::in(array_keys(IndicatorField::TYPES))],
            'fields.*.section' => ['nullable', 'string', 'max:150'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string', 'max:500'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.is_active' => ['nullable', 'boolean'],
            'fields.*.show_in_summary' => ['nullable', 'boolean'],
            'fields.*.allow_decimal' => ['nullable', 'boolean'],
            'fields.*.default_value' => ['nullable', 'string', 'max:255'],
            'fields.*.min_value' => $number,
            'fields.*.max_value' => $number,
            'fields.*.min_length' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'fields.*.max_length' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'fields.*.format' => ['nullable', 'string', Rule::in(array_keys(IndicatorField::FORMATS))],
            'fields.*.max_field_key' => ['nullable', 'string', 'max:64'],
            'fields.*.visible_when_key' => ['nullable', 'string', 'max:64'],
            'fields.*.visible_when_values' => ['nullable', 'array', 'max:100'],
            'fields.*.visible_when_values.*' => ['string', 'max:100'],
            'fields.*.options' => ['nullable', 'array', 'max:100'],
            'fields.*.options.*.label' => ['required', 'string', 'max:255'],
            'fields.*.options.*.value' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'fields.present' => 'Data formulir tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'fields.array' => 'Data formulir tidak terbaca. Muat ulang halaman lalu coba lagi.',
            'fields.*.key.regex' => 'Kunci field hanya boleh berisi huruf kecil, angka, dan garis bawah, serta diawali huruf.',
            'fields.*.options.*.value.regex' => 'Nilai opsi hanya boleh berisi huruf, angka, garis bawah, dan strip.',
        ];
    }

    public function attributes(): array
    {
        return [
            'fields.*.key' => 'kunci field',
            'fields.*.label' => 'label',
            'fields.*.type' => 'tipe field',
            'fields.*.section' => 'judul bagian',
            'fields.*.placeholder' => 'placeholder',
            'fields.*.help_text' => 'teks bantuan',
            'fields.*.default_value' => 'nilai bawaan',
            'fields.*.min_value' => 'nilai minimum',
            'fields.*.max_value' => 'nilai maksimum',
            'fields.*.min_length' => 'panjang minimum',
            'fields.*.max_length' => 'panjang maksimum',
            'fields.*.format' => 'format isian',
            'fields.*.visible_when_key' => 'syarat tampil',
            'fields.*.visible_when_values' => 'pilihan syarat tampil',
            'fields.*.options' => 'opsi',
            'fields.*.options.*.label' => 'label opsi',
            'fields.*.options.*.value' => 'nilai opsi',
        ];
    }

    /**
     * Rules that depend on several attributes, on other fields, or on
     * observations already recorded for the indicator.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fields = $this->normalize($this->input('fields', []));
            $fail = fn (int $index, string $attribute, string $message) => $validator->errors()->add("fields.{$index}.{$attribute}", $message);

            $version = $this->route('version');
            $ownIds = $version->fields()->pluck('id')->all();
            $keys = array_column($fields, 'key');
            $numberKeys = array_column(array_filter($fields, fn ($f) => $f['type'] === IndicatorField::TYPE_NUMBER), 'key');

            foreach ($fields as $index => $field) {
                $type = $field['type'];

                if ($field['id'] !== null && ! in_array($field['id'], $ownIds, true)) {
                    $fail($index, 'label', 'Field ini bukan bagian dari draf yang sedang diedit. Muat ulang halaman.');
                }

                if (in_array($field['key'], IndicatorField::RESERVED_KEYS, true)) {
                    $fail($index, 'key', "Kunci \"{$field['key']}\" dipakai oleh sistem. Gunakan kunci lain.");
                } elseif (count(array_keys($keys, $field['key'], true)) > 1) {
                    $fail($index, 'key', "Kunci \"{$field['key']}\" dipakai lebih dari satu field.");
                }

                if ($field['min_value'] !== null && $field['max_value'] !== null && $field['min_value'] > $field['max_value']) {
                    $fail($index, 'max_value', 'Nilai maksimum tidak boleh lebih kecil dari nilai minimum.');
                }

                if (in_array($type, IndicatorField::TEXT_TYPES, true)) {
                    $limit = IndicatorField::LENGTH_LIMITS[$type]['max'];
                    $effectiveMax = $field['max_length'] ?? IndicatorField::LENGTH_LIMITS[$type]['default'];

                    if ($field['max_length'] !== null && $field['max_length'] > $limit) {
                        $fail($index, 'max_length', "Panjang maksimum untuk tipe ini adalah {$limit} karakter.");
                    }

                    if ($field['min_length'] !== null && $field['min_length'] > $effectiveMax) {
                        $fail($index, 'min_length', 'Panjang minimum tidak boleh melebihi panjang maksimum.');
                    }
                }

                if (in_array($type, IndicatorField::CHOICE_TYPES, true)) {
                    $values = array_column($field['options'], 'value');

                    if ($values === []) {
                        $fail($index, 'options', 'Tambahkan minimal satu opsi.');
                    } elseif (count($values) !== count(array_unique($values))) {
                        $fail($index, 'options', 'Nilai setiap opsi harus berbeda.');
                    }
                }

                if ($field['max_field_key'] !== null
                    && ($field['max_field_key'] === $field['key'] || ! in_array($field['max_field_key'], $numberKeys, true))) {
                    $fail($index, 'max_field_key', 'Field pembanding harus field bertipe Angka lain di formulir ini.');
                }

                if ($field['visible_when_key'] !== null && ($message = $this->conditionError($field, $index, $fields))) {
                    $fail($index, 'visible_when_key', $message);
                }

                if ($field['default_value'] !== null && ($message = $this->defaultValueError($field))) {
                    $fail($index, 'default_value', $message);
                }
            }

            // Fields with recorded answers stay in the form with the same key and type.
            $byKey = array_column($fields, null, 'key');

            foreach (FormBuilder::lockedFields($version->indicator) as $key => $type) {
                if (! isset($byKey[$key])) {
                    $validator->errors()->add('fields', "Field dengan kunci \"{$key}\" sudah memiliki data observasi, sehingga tidak dapat dihapus atau diganti kuncinya. Nonaktifkan field tersebut bila tidak lagi digunakan.");
                } elseif ($byKey[$key]['type'] !== $type) {
                    $fail(array_search($key, $keys, true), 'type', 'Tipe field yang sudah memiliki data observasi tidak dapat diubah.');
                }
            }

            $this->normalized = $fields;
        }];
    }

    /**
     * The validated field definitions, ready for FormBuilder::save().
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->normalized;
    }

    public function intent(): string
    {
        return $this->input('intent') ?: 'save';
    }

    /**
     * Fill in generated keys and option values, and drop every setting that
     * does not apply to the field's type.
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    private function normalize(array $input): array
    {
        $blank = fn ($value) => $value === null || $value === '';
        $taken = array_filter(array_column($input, 'key'), fn ($key) => ! $blank($key));
        $fields = [];

        foreach ($input as $field) {
            $type = $field['type'];
            $isText = in_array($type, IndicatorField::TEXT_TYPES, true);
            $isNumber = $type === IndicatorField::TYPE_NUMBER;
            $isChoice = in_array($type, IndicatorField::CHOICE_TYPES, true);
            $get = fn (string $name) => $blank($field[$name] ?? null) ? null : $field[$name];

            $key = $get('key');

            if ($key === null) {
                $key = $this->uniqueSlug((string) $field['label'], $taken, 'field', 64);
                $taken[] = $key;
            }

            $condition = $get('visible_when_key') === null ? [] : array_values(array_unique(array_map('strval', array_filter(
                (array) ($field['visible_when_values'] ?? []),
                fn ($value) => ! $blank($value),
            ))));

            $options = [];
            $optionValues = [];

            foreach ($isChoice ? ($field['options'] ?? []) : [] as $option) {
                $value = $blank($option['value'] ?? null)
                    ? $this->uniqueSlug((string) $option['label'], $optionValues, 'opsi', 100)
                    : (string) $option['value'];
                $optionValues[] = $value;
                $options[] = ['value' => $value, 'label' => trim((string) $option['label'])];
            }

            $fields[] = [
                'id' => $get('id') === null ? null : (int) $get('id'),
                'key' => $key,
                'label' => trim((string) $field['label']),
                'type' => $type,
                'section' => $get('section') === null ? null : trim((string) $get('section')),
                'placeholder' => ($isText || $isNumber || $type === IndicatorField::TYPE_SELECT) ? $get('placeholder') : null,
                'help_text' => $get('help_text'),
                'is_required' => (bool) ($field['is_required'] ?? true),
                'is_active' => (bool) ($field['is_active'] ?? true),
                'show_in_summary' => (bool) ($field['show_in_summary'] ?? false),
                'default_value' => $get('default_value') === null ? null : (string) $get('default_value'),
                'min_value' => $isNumber && $get('min_value') !== null ? (float) $get('min_value') : null,
                'max_value' => $isNumber && $get('max_value') !== null ? (float) $get('max_value') : null,
                'allow_decimal' => $isNumber && ($field['allow_decimal'] ?? false),
                'min_length' => $isText && $get('min_length') !== null ? (int) $get('min_length') : null,
                'max_length' => $isText && $get('max_length') !== null ? (int) $get('max_length') : null,
                'format' => $type === IndicatorField::TYPE_TEXT ? $get('format') : null,
                'max_field_key' => $isNumber ? $get('max_field_key') : null,
                // Shown only for some answers of another field; both or neither.
                'visible_when_key' => $condition === [] ? null : $get('visible_when_key'),
                'visible_when_values' => $condition === [] ? null : $condition,
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * A field can depend on one earlier single-choice field (dropdown or
     * single choice) that is always shown, for one or more of its options.
     *
     * @param  array<string, mixed>  $field
     * @param  list<array<string, mixed>>  $fields
     */
    private function conditionError(array $field, int $index, array $fields): ?string
    {
        $position = array_search($field['visible_when_key'], array_column($fields, 'key'), true);
        $trigger = $position === false ? null : $fields[$position];

        return match (true) {
            $trigger === null => 'Field syarat tampil tidak ditemukan di formulir ini.',
            $position >= $index => 'Field syarat tampil harus berada sebelum field ini.',
            ! in_array($trigger['type'], IndicatorField::CONDITION_TYPES, true) => 'Field syarat tampil harus bertipe Dropdown atau Pilihan Tunggal.',
            $trigger['visible_when_key'] !== null => 'Field syarat tampil tidak boleh memiliki syarat tampil sendiri.',
            ! $trigger['is_active'] => 'Field syarat tampil sedang nonaktif.',
            array_diff($field['visible_when_values'], array_column($trigger['options'], 'value')) !== [] => 'Pilihan syarat tampil tidak ada pada field "'.$trigger['label'].'".',
            default => null,
        };
    }

    /**
     * A snake_case identifier derived from a label, unique among $taken.
     *
     * @param  list<string>  $taken
     */
    private function uniqueSlug(string $label, array $taken, string $fallback, int $maxLength): string
    {
        $base = Str::slug($label, '_');
        $base = preg_match('/^[a-z]/', $base) ? $base : trim($fallback.'_'.$base, '_');
        $base = rtrim(substr($base, 0, $maxLength - 4), '_');

        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true) || in_array($candidate, IndicatorField::RESERVED_KEYS, true)) {
            $candidate = $base.'_'.$suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function defaultValueError(array $field): ?string
    {
        $default = $field['default_value'];

        switch ($field['type']) {
            case IndicatorField::TYPE_NUMBER:
                if (! is_numeric($default) || (! $field['allow_decimal'] && (string) (int) $default !== $default)) {
                    return 'Nilai bawaan harus berupa angka'.($field['allow_decimal'] ? '.' : ' bulat.');
                }

                if (($field['min_value'] !== null && $default < $field['min_value'])
                    || ($field['max_value'] !== null && $default > $field['max_value'])) {
                    return 'Nilai bawaan berada di luar batas minimum/maksimum.';
                }

                return null;

            case IndicatorField::TYPE_DATE:
            case IndicatorField::TYPE_TIME:
            case IndicatorField::TYPE_DATETIME:
                $format = IndicatorField::TEMPORAL_FORMATS[$field['type']];
                $message = 'Nilai bawaan harus berupa '.($field['type'] === IndicatorField::TYPE_TIME ? 'jam' : 'tanggal').' yang valid.';

                try {
                    return Carbon::createFromFormat($format, $default)->format($format) === $default ? null : $message;
                } catch (Throwable) {
                    return $message;
                }

            case IndicatorField::TYPE_YES_NO:
                return in_array($default, ['0', '1'], true) ? null : 'Nilai bawaan harus Ya atau Tidak.';

            case IndicatorField::TYPE_SELECT:
            case IndicatorField::TYPE_RADIO:
            case IndicatorField::TYPE_CHECKBOX:
                return in_array($default, array_column($field['options'], 'value'), true)
                    ? null
                    : 'Nilai bawaan harus salah satu opsi yang tersedia.';

            default:
                $max = $field['max_length'] ?? IndicatorField::LENGTH_LIMITS[$field['type']]['default'];

                return mb_strlen($default) > $max ? 'Nilai bawaan melebihi panjang maksimum.' : null;
        }
    }
}

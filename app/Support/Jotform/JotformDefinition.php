<?php

namespace App\Support\Jotform;

use App\Models\IndicatorField;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * One exported Jotform form (form.json + questions.json), translated into
 * SIMUTU field definitions.
 *
 * Only the form's identity and its questions are read. Nothing else in the
 * export (owner, submission counters, URLs) is used, and submissions are
 * not part of these files at all.
 */
class JotformDefinition
{
    /** Question types that are layout, not input. */
    private const SKIPPED_TYPES = [
        'control_head' => 'Judul formulir',
        'control_button' => 'Tombol kirim',
        'control_text' => 'Teks keterangan',
        'control_divider' => 'Garis pemisah',
        'control_pagebreak' => 'Pemisah halaman',
        'control_image' => 'Gambar',
        'control_collapse' => 'Pembatas bagian',
        // Computed by Jotform from other answers; SIMUTU has no formulas.
        'control_calculation' => 'Hasil perhitungan otomatis Jotform',
    ];

    /** Jotform type => label shown in the mapping preview. */
    private const TYPE_LABELS = [
        'control_textbox' => 'Short Text',
        'control_textarea' => 'Long Text',
        'control_number' => 'Number',
        'control_datetime' => 'Date Picker',
        'control_time' => 'Time',
        'control_dropdown' => 'Dropdown',
        'control_radio' => 'Single Choice',
        'control_checkbox' => 'Multiple Choice',
        'control_matrix' => 'Input Table',
        'control_widget' => 'Widget',
        'control_calculation' => 'Form Calculation',
        'control_head' => 'Heading',
        'control_button' => 'Submit',
    ];

    /** Column sets of a two-column matrix that mean Yes / No. */
    private const YES_NO_COLUMNS = [['√', '☓'], ['√', 'x'], ['ya', 'tidak']];

    /**
     * @param  list<array<string, mixed>>  $fields  SIMUTU field definitions, in form order
     * @param  list<array<string, mixed>>  $questions  every Jotform question with how it was handled
     * @param  list<string>  $errors  reasons this form cannot be imported
     */
    private function __construct(
        public readonly string $directory,
        public readonly string $formId,
        public readonly string $title,
        public readonly string $status,
        public readonly array $fields,
        public readonly array $questions,
        public readonly array $errors,
        public readonly string $hash,
    ) {}

    /**
     * @throws RuntimeException when the directory does not hold a readable export
     */
    public static function fromDirectory(string $path): self
    {
        $form = self::readJson($path.DIRECTORY_SEPARATOR.'form.json');
        $questions = self::readJson($path.DIRECTORY_SEPARATOR.'questions.json');

        $formId = (string) ($form['id'] ?? '');

        if (! preg_match('/^\d{5,32}$/', $formId)) {
            throw new RuntimeException('form.json tidak memuat Form ID Jotform yang valid.');
        }

        $title = trim(preg_replace('/\s+/', ' ', (string) ($form['title'] ?? '')));

        if ($title === '') {
            throw new RuntimeException('form.json tidak memuat judul formulir.');
        }

        $questions = array_values(array_filter($questions, 'is_array'));
        usort($questions, fn (array $a, array $b) => [(int) ($a['order'] ?? 0), (int) ($a['qid'] ?? 0)] <=> [(int) ($b['order'] ?? 0), (int) ($b['qid'] ?? 0)]);

        [$fields, $report, $errors] = self::translate($questions);

        if ($fields === []) {
            $errors[] = 'Formulir tidak memiliki pertanyaan yang dapat diimpor.';
        }

        return new self(
            directory: basename($path),
            formId: $formId,
            title: $title,
            status: (string) ($form['status'] ?? ''),
            fields: $fields,
            questions: $report,
            errors: $errors,
            hash: hash('sha256', json_encode([$formId, $fields], JSON_THROW_ON_ERROR)),
        );
    }

    public function isImportable(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('Berkas '.basename($file).' tidak ditemukan.');
        }

        // Exports written by PowerShell start with a UTF-8 byte order mark.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Berkas '.basename($file).' bukan JSON yang valid.');
        }

        // API responses wrap the payload in "content".
        $content = is_array($decoded) && is_array($decoded['content'] ?? null) ? $decoded['content'] : $decoded;

        if (! is_array($content)) {
            throw new RuntimeException('Isi berkas '.basename($file).' tidak dikenali.');
        }

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, list<string>}
     */
    private static function translate(array $questions): array
    {
        $fields = [];
        $report = [];
        $errors = [];
        $observationDateTaken = false;

        foreach ($questions as $question) {
            $type = (string) ($question['type'] ?? '');
            $qid = (string) ($question['qid'] ?? '');
            $label = self::clean($question['text'] ?? '');

            $entry = [
                'qid' => $qid,
                'order' => (int) ($question['order'] ?? 0),
                'label' => $label,
                'jotform_type' => self::TYPE_LABELS[$type] ?? $type,
                'required' => self::isYes($question['required'] ?? 'No'),
                'result' => 'field',
                'note' => null,
                'fields' => [],
            ];

            if (isset(self::SKIPPED_TYPES[$type])) {
                $entry['result'] = 'skipped';
                $entry['note'] = self::SKIPPED_TYPES[$type].' — bukan isian, tidak diimpor.';
                $report[] = $entry;

                continue;
            }

            if (self::isYes($question['hidden'] ?? 'No')) {
                $entry['result'] = 'skipped';
                $entry['note'] = 'Pertanyaan tersembunyi di Jotform, tidak diimpor.';
                $report[] = $entry;

                continue;
            }

            // The form's own date is SIMUTU's built-in "Tanggal Observasi".
            if ($type === 'control_datetime' && ! self::isYes($question['allowTime'] ?? 'No')
                && ! $observationDateTaken && preg_match('/tanggal/i', $label)) {
                $observationDateTaken = true;
                $entry['result'] = 'builtin';
                $entry['note'] = 'Dipetakan ke field bawaan "Tanggal Observasi".';
                $report[] = $entry;

                continue;
            }

            $built = self::fieldsFor($type, $question, $label, $qid);

            if (is_string($built)) {
                $entry['result'] = 'unsupported';
                $entry['note'] = $built;
                $errors[] = "Pertanyaan #{$qid} \"".($label ?: $type)."\": {$built}";
                $report[] = $entry;

                continue;
            }

            [$questionFields, $note] = $built;
            $entry['note'] = $note;
            $entry['fields'] = $questionFields;
            $report[] = $entry;

            array_push($fields, ...$questionFields);
        }

        return [$fields, $report, $errors];
    }

    /**
     * SIMUTU fields for one question, or a string explaining why it is not supported.
     *
     * @param  array<string, mixed>  $question
     * @return array{list<array<string, mixed>>, ?string}|string
     */
    private static function fieldsFor(string $type, array $question, string $label, string $qid): array|string
    {
        $base = [
            'external_id' => $qid,
            'label' => $label !== '' ? $label : 'Pertanyaan '.$qid,
            'section' => null,
            'is_required' => self::isYes($question['required'] ?? 'No'),
            'placeholder' => self::blank($question['hint'] ?? null),
            'help_text' => self::blank(self::clean($question['description'] ?? '')) ?? self::blank(self::clean($question['subLabel'] ?? '')),
            'default_value' => null,
            'min_value' => null,
            'max_value' => null,
            'max_length' => null,
            'options' => [],
        ];

        switch ($type) {
            case 'control_textbox':
            case 'control_textarea':
                $fieldType = $type === 'control_textbox' ? IndicatorField::TYPE_TEXT : IndicatorField::TYPE_TEXTAREA;
                $limit = IndicatorField::LENGTH_LIMITS[$fieldType]['max'];
                $maxsize = (int) ($question['maxsize'] ?? 0);
                $default = self::blank($question['defaultValue'] ?? null);

                return [[[
                    ...$base,
                    'type' => $fieldType,
                    'max_length' => $maxsize > 0 && $maxsize <= $limit ? $maxsize : null,
                    'default_value' => $default !== null && mb_strlen($default) <= 255 ? $default : null,
                ]], null];

            case 'control_number':
                $numeric = fn ($value) => is_numeric($value) ? (float) $value : null;
                $default = $question['defaultValue'] ?? null;

                return [[[
                    ...$base,
                    'type' => IndicatorField::TYPE_NUMBER,
                    'min_value' => $numeric($question['minValue'] ?? null),
                    'max_value' => $numeric($question['maxValue'] ?? null),
                    'default_value' => is_numeric($default) && (string) (int) $default === (string) $default ? (string) $default : null,
                ]], null];

            case 'control_time':
                if (self::isYes($question['range'] ?? 'No')) {
                    return 'Rentang jam (Time Range) belum didukung.';
                }

                return [[[...$base, 'type' => IndicatorField::TYPE_TIME, 'placeholder' => null]], null];

            case 'control_datetime':
                $withTime = self::isYes($question['allowTime'] ?? 'No');

                return [[[
                    ...$base,
                    'type' => $withTime ? IndicatorField::TYPE_DATETIME : IndicatorField::TYPE_DATE,
                    'placeholder' => null,
                ]], null];

            case 'control_dropdown':
            case 'control_radio':
            case 'control_checkbox':
                $options = self::options(self::split($question['options'] ?? ''));

                if ($options === []) {
                    return 'Pertanyaan pilihan tanpa opsi.';
                }

                if (($question['special'] ?? 'None') !== 'None' && self::blank($question['special'] ?? null) !== null) {
                    return 'Daftar opsi bawaan Jotform ("'.$question['special'].'") belum didukung.';
                }

                $fieldType = match (true) {
                    $type === 'control_checkbox', self::isYes($question['multipleSelections'] ?? 'No') => IndicatorField::TYPE_CHECKBOX,
                    $type === 'control_radio' => IndicatorField::TYPE_RADIO,
                    default => IndicatorField::TYPE_SELECT,
                };

                $selected = self::clean($question['selected'] ?? '');
                $default = collect($options)->firstWhere('label', $selected)['value'] ?? null;

                return [[[
                    ...$base,
                    'type' => $fieldType,
                    'placeholder' => null,
                    'default_value' => $default,
                    'options' => $options,
                ]], self::isYes($question['allowOther'] ?? 'No') ? 'Opsi "Lainnya" (isian bebas) tidak diimpor.' : null];

            case 'control_matrix':
                return self::matrixFields($question, $label, $qid, $base);

            case 'control_widget':
                return self::widgetFields($question, $base);

            default:
                return 'Tipe pertanyaan Jotform "'.$type.'" belum didukung.';
        }
    }

    /**
     * Widgets are third-party controls. Only the "Fancy Range Slider" with a
     * single handle is supported: it asks for one number between its minimum
     * and maximum, so it becomes a Number field with the same range and step.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $base
     * @return array{list<array<string, mixed>>, ?string}|string
     */
    private static function widgetFields(array $question, array $base): array|string
    {
        $source = (string) ($question['frameSrc'] ?? $question['finalSrc'] ?? '');
        $name = self::clean($question['builderLabel'] ?? '') ?: 'tanpa nama';

        if (! str_contains($source, 'widgets.jotform.io/rangeSlider')) {
            return 'Widget Jotform "'.$name.'" belum didukung.';
        }

        if (($question['typez'] ?? 'single') !== 'single') {
            return 'Range slider dengan dua penanda (rentang nilai) belum didukung.';
        }

        $min = $question['min'] ?? null;
        $max = $question['max'] ?? null;
        $step = (string) ($question['step'] ?? '1');

        if (! is_numeric($min) || ! is_numeric($max) || (float) $min > (float) $max) {
            return 'Range slider tanpa batas nilai yang valid.';
        }

        return [[[
            ...$base,
            'type' => IndicatorField::TYPE_NUMBER,
            'placeholder' => null,
            'min_value' => (float) $min,
            'max_value' => (float) $max,
            // Whole steps stay whole numbers, as on the slider.
            'allow_decimal' => ! (is_numeric($step) && floor((float) $step) == (float) $step),
        ]], 'Range slider '.self::number($min).'–'.self::number($max).' menjadi isian angka dengan rentang yang sama.'];
    }

    private static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * A matrix (input table) has no SIMUTU equivalent: each row becomes its
     * own field, with the columns as its choices and the matrix title as the
     * section heading.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $base
     * @return array{list<array<string, mixed>>, ?string}|string
     */
    private static function matrixFields(array $question, string $label, string $qid, array $base): array|string
    {
        $inputType = (string) ($question['inputType'] ?? 'Radio Button');

        if (! in_array($inputType, ['Radio Button', 'Check Box'], true)) {
            return 'Tabel isian dengan tipe sel "'.$inputType.'" belum didukung.';
        }

        $columns = self::split($question['mcolumns'] ?? '');
        $rows = self::split($question['mrows'] ?? '');

        if ($columns === []) {
            return 'Tabel isian tanpa kolom pilihan.';
        }

        // A table without row labels is a single question.
        $rows = $rows === [] ? [''] : $rows;

        $requirement = (string) ($question['required'] ?? 'No');
        $everyRow = stripos($requirement, 'every row') !== false || self::isYes($requirement);
        $atLeastOne = stripos($requirement, 'at least one') !== false;
        $required = $everyRow || ($atLeastOne && count($rows) === 1);

        $isYesNo = $inputType === 'Radio Button'
            && in_array(array_map('mb_strtolower', $columns), self::YES_NO_COLUMNS, true);

        $fields = [];

        foreach ($rows as $index => $row) {
            $fields[] = [
                ...$base,
                'external_id' => $qid.':'.$index,
                'label' => $row !== '' ? $row : ($label !== '' ? $label : 'Pertanyaan '.$qid),
                'section' => $row !== '' && $label !== '' ? $label : null,
                'is_required' => $required,
                'placeholder' => null,
                'type' => match (true) {
                    $isYesNo => IndicatorField::TYPE_YES_NO,
                    $inputType === 'Check Box' => IndicatorField::TYPE_CHECKBOX,
                    default => IndicatorField::TYPE_RADIO,
                },
                'options' => $isYesNo ? [] : self::options($columns),
            ];
        }

        $notes = [count($rows) > 1 ? 'Tabel isian: setiap baris menjadi satu field.' : 'Tabel isian satu baris menjadi satu field.'];

        if ($isYesNo) {
            $notes[] = 'Kolom '.implode(' / ', $columns).' menjadi Ya / Tidak.';
        }

        if ($atLeastOne && count($rows) > 1) {
            $notes[] = 'Aturan "minimal satu baris terisi" tidak dapat diterapkan; baris dibuat opsional.';
        }

        return [$fields, implode(' ', $notes)];
    }

    /**
     * @param  list<string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        $options = [];
        $taken = [];

        foreach ($labels as $label) {
            $base = Str::slug($label, '_');
            $base = substr($base !== '' ? $base : 'opsi', 0, 90);
            $value = $base;

            for ($suffix = 2; in_array($value, $taken, true); $suffix++) {
                $value = $base.'_'.$suffix;
            }

            $taken[] = $value;
            $options[] = ['value' => $value, 'label' => mb_substr($label, 0, 255)];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $list): array
    {
        return array_values(array_filter(
            array_map(self::clean(...), explode('|', (string) $list)),
            fn (string $item) => $item !== '',
        ));
    }

    /** Plain single-line text: Jotform labels may carry HTML. */
    private static function clean(mixed $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 255);
    }

    private static function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private static function isYes(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1'], true);
    }
}

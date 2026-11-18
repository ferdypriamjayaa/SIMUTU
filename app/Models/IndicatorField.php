<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndicatorField extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_TIME = 'time';

    public const TYPE_DATETIME = 'datetime';

    public const TYPE_SELECT = 'select';

    public const TYPE_RADIO = 'radio';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_YES_NO = 'yes_no';

    /** Type => Indonesian label shown in the form builder. */
    public const TYPES = [
        self::TYPE_TEXT => 'Teks Singkat',
        self::TYPE_TEXTAREA => 'Teks Panjang',
        self::TYPE_NUMBER => 'Angka',
        self::TYPE_DATE => 'Tanggal',
        self::TYPE_TIME => 'Jam',
        self::TYPE_DATETIME => 'Tanggal & Jam',
        self::TYPE_SELECT => 'Pilihan Dropdown',
        self::TYPE_RADIO => 'Pilihan Tunggal',
        self::TYPE_CHECKBOX => 'Pilihan Ganda',
        self::TYPE_YES_NO => 'Ya / Tidak',
    ];

    public const CHOICE_TYPES = [self::TYPE_SELECT, self::TYPE_RADIO, self::TYPE_CHECKBOX];

    /** Single-choice types another field's visibility can depend on. */
    public const CONDITION_TYPES = [self::TYPE_SELECT, self::TYPE_RADIO];

    /** A dropdown with at least this many options can be searched on the observation form. */
    public const SEARCHABLE_MIN_OPTIONS = 7;

    public const TEXT_TYPES = [self::TYPE_TEXT, self::TYPE_TEXTAREA];

    /**
     * Preset character rules for text fields. Presets instead of free regex,
     * so a form definition can never carry an invalid or runaway pattern.
     *
     * @var array<string, array{label: string, pattern: string}>
     */
    public const FORMATS = [
        'code' => ['label' => 'Kode (huruf, angka, titik, strip, garis miring)', 'pattern' => '/^[A-Za-z0-9.\-\/]+$/'],
        'digits' => ['label' => 'Hanya angka', 'pattern' => '/^[0-9]+$/'],
        'letters' => ['label' => 'Hanya huruf', 'pattern' => '/^[\pL\s.\',\-]+$/u'],
    ];

    /** Input formats of the date and time types, as browsers submit them. */
    public const TEMPORAL_FORMATS = [
        self::TYPE_DATE => 'Y-m-d',
        self::TYPE_TIME => 'H:i',
        self::TYPE_DATETIME => 'Y-m-d\TH:i',
    ];

    /** Default and hard upper limits for text length, per type. */
    public const LENGTH_LIMITS = [
        self::TYPE_TEXT => ['default' => 255, 'max' => 255],
        self::TYPE_TEXTAREA => ['default' => 2000, 'max' => 5000],
    ];

    /** Request keys the observation form itself uses; never valid as a field key. */
    public const RESERVED_KEYS = ['id', 'indicator_id', 'unit_id', 'user_id', 'observation_date', 'indicator_form_version_id', 'data'];

    protected $fillable = [
        'indicator_form_version_id',
        'key',
        'label',
        'type',
        'section',
        'placeholder',
        'help_text',
        'is_required',
        'is_active',
        'show_in_summary',
        'sort_order',
        'default_value',
        'min_value',
        'max_value',
        'allow_decimal',
        'min_length',
        'max_length',
        'format',
        'max_field_key',
        'visible_when_key',
        'visible_when_values',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'show_in_summary' => 'boolean',
            'allow_decimal' => 'boolean',
            'sort_order' => 'integer',
            'min_value' => 'float',
            'max_value' => 'float',
            'min_length' => 'integer',
            'max_length' => 'integer',
            'visible_when_values' => 'array',
        ];
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorFormVersion::class, 'indicator_form_version_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(IndicatorFieldOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ObservationValue::class);
    }

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Effective maximum length for text types. */
    public function maxLength(): int
    {
        return $this->max_length ?? self::LENGTH_LIMITS[$this->type]['default'] ?? 255;
    }

    /**
     * Shown only when another (single-choice) field has one of the given values.
     */
    public function isConditional(): bool
    {
        return $this->visible_when_key !== null && $this->visible_when_values !== null && $this->visible_when_values !== [];
    }

    /**
     * Whether the field applies to these answers (input keyed by field key).
     * A hidden field is neither required, validated nor stored.
     *
     * @param  array<string, mixed>  $answers
     */
    public function isVisibleFor(array $answers): bool
    {
        if (! $this->isConditional()) {
            return true;
        }

        $value = $answers[$this->visible_when_key] ?? null;

        return is_scalar($value) && in_array((string) $value, array_map('strval', $this->visible_when_values), true);
    }
}

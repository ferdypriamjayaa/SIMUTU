<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer of an observation. Checkbox fields store one row per ticked
 * option; every other field type stores a single row.
 */
class ObservationValue extends Model
{
    protected $fillable = [
        'observation_id',
        'indicator_field_id',
        'indicator_field_option_id',
        'value',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(IndicatorField::class, 'indicator_field_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(IndicatorFieldOption::class, 'indicator_field_option_id');
    }
}

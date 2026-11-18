<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * IMP Unit indicators this unit is responsible for (indicators.unit_id).
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * HRIS division/department placements that belong to this unit.
     */
    public function hrisMappings(): HasMany
    {
        return $this->hasMany(HrisUnitMapping::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /**
     * Indicators mapped to this unit (indicator_unit). For INM indicators
     * the mapping also decides that the unit may record observations.
     */
    public function assignedIndicators(): BelongsToMany
    {
        return $this->belongsToMany(Indicator::class)->withTimestamps();
    }
}

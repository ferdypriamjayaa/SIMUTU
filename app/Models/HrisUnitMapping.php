<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HRIS placement (division + department of a hospital company) that
 * belongs to a SIMUTU unit. Employees placed there are offered when that
 * unit records an observation.
 */
class HrisUnitMapping extends Model
{
    protected $fillable = [
        'unit_id',
        'company_code',
        'division',
        'department',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @param  array{division: ?string, department: ?string}  $employee
     */
    public function matches(array $employee): bool
    {
        return $employee['division'] === $this->division && $employee['department'] === $this->department;
    }
}

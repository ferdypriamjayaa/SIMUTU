<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Observation extends Model
{
    protected $fillable = [
        'indicator_id',
        'indicator_form_version_id',
        'unit_id',
        'user_id',
        'observation_date',
        'officer_name',
        'patient_name',
        'medication_compliant',
        'procedure_compliant',
        'patient_name_verified',
        'birth_date_verified',
        'notes',
        'data'
    ];

    protected function casts(): array
    {
        return [
            'observation_date' => 'date',
            'medication_compliant' => 'boolean',
            'procedure_compliant' => 'boolean',
            'patient_name_verified' => 'boolean',
            'birth_date_verified' => 'boolean',
            'data' => 'array',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * The exact form version this observation was entered on. NULL for
     * records created before form versions existed.
     */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(IndicatorFormVersion::class, 'indicator_form_version_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ObservationValue::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who recorded it, for reports: the user's name, or "Publik (survei online)"
     * for an answer to the public patient survey (no account).
     */
    public function submitterName(): string
    {
        return $this->user?->name ?? ($this->user_id === null ? \App\Support\PublicSurvey::SUBMITTER : '-');
    }

    /**
     * Eager-load everything needed to read an observation's answers.
     */
    public function scopeWithAnswers(Builder $query): Builder
    {
        return $query->with([
            'values',
            'formVersion.fields.options',
            'indicator.baselineFormVersion.fields.options',
        ]);
    }

    /**
     * Limit observations to what the user may see: categories of their view
     * permissions (inm.view / imp.view), and every unit with data.view_all_units,
     * otherwise only their own unit (nothing without one).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $codes = $user->categoryCodes('view');

        if ($codes === []) {
            return $query->whereRaw('1 = 0');
        }

        if (count($codes) < count(array_merge(...array_values(Permissions::AREAS)))) {
            $query->whereHas('indicator.category', fn (Builder $q) => $q->whereIn('code', $codes));
        }

        if ($user->canViewAllUnits()) {
            return $query;
        }

        if ($user->unit_id) {
            return $query->where('unit_id', $user->unit_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Apply history/report filters: start_date, end_date, indicator_id, unit_id.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['start_date'] ?? null, fn(Builder $q, $date) => $q->whereDate('observation_date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn(Builder $q, $date) => $q->whereDate('observation_date', '<=', $date))
            ->when($filters['indicator_id'] ?? null, fn(Builder $q, $id) => $q->where('indicator_id', $id))
            ->when($filters['unit_id'] ?? null, fn(Builder $q, $id) => $q->where('unit_id', $id));
    }
}

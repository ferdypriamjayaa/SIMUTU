<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Indicator extends Model
{
    protected $fillable = [
        'indicator_category_id',
        'unit_id',
        'name',
        'is_active',
    ];
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IndicatorCategory::class, 'indicator_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function formVersions(): HasMany
    {
        return $this->hasMany(IndicatorFormVersion::class)->orderByDesc('version');
    }

    /**
     * The form PIC Unit fills in right now.
     */
    public function publishedFormVersion(): HasOne
    {
        return $this->hasOne(IndicatorFormVersion::class)->where('status', IndicatorFormVersion::STATUS_PUBLISHED);
    }

    /**
     * The version being edited in the form builder, if any.
     */
    public function draftFormVersion(): HasOne
    {
        return $this->hasOne(IndicatorFormVersion::class)->where('status', IndicatorFormVersion::STATUS_DRAFT);
    }

    /**
     * The first form that was ever in use. Observations recorded before form
     * versions existed are read through it.
     */
    public function baselineFormVersion(): HasOne
    {
        return $this->hasOne(IndicatorFormVersion::class)->ofMany(
            ['version' => 'min'],
            fn (Builder $query) => $query->where('status', '!=', IndicatorFormVersion::STATUS_DRAFT),
        );
    }

    /**
     * Units this indicator is mapped to (indicator_unit). For INM indicators
     * these are the units that may record observations.
     */
    public function assignedUnits(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    /**
     * Categories whose indicators PIC Unit records through the observation
     * form, for the units they are mapped to (indicator_unit). IMP Unit is
     * not included: its mapping is administrative only so far.
     */
    public const INPUT_CATEGORIES = ['INM', 'IMP_RS'];

    public function isInputCategory(): bool
    {
        return in_array($this->category?->code, self::INPUT_CATEGORIES, true);
    }

    /**
     * Indicators of the input categories, with or without a form.
     */
    public function scopeInputCategory(Builder $query): Builder
    {
        return $query->whereHas('category', fn (Builder $q) => $q->whereIn('code', self::INPUT_CATEGORIES));
    }

    /**
     * Indicators of any category with a form observations can be reported
     * against: one of the original INM forms, or a non-draft Form Builder version.
     */
    public function scopeWithReportForm(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNotNull('form_type')
            ->orWhereHas('formVersions', fn (Builder $v) => $v->where('status', '!=', IndicatorFormVersion::STATUS_DRAFT)));
    }

    /**
     * Indicators of one category, by its code (INM, IMP_RS, IMP_UNIT).
     */
    public function scopeInCategory(Builder $query, string $code): Builder
    {
        return $query->whereHas('category', fn (Builder $q) => $q->where('code', $code));
    }

    /**
     * Active INM and IMP RS indicators with a published observation form.
     */
    public function scopeObservable(Builder $query): Builder
    {
        return $query->inputCategory()->where('is_active', true)->whereHas('publishedFormVersion');
    }

    /**
     * Indicators of the categories the user may record (inm.create / imp.create).
     */
    public function scopeCreatableBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('category', fn (Builder $c) => $c->whereIn('code', $user->categoryCodes('create')));
    }

    /**
     * Indicators assigned to the given unit.
     */
    public function scopeAssignedTo(Builder $query, ?int $unitId): Builder
    {
        return $query->whereHas('assignedUnits', fn (Builder $q) => $q->whereKey($unitId ?? 0));
    }

    /**
     * Indicators the user can report on: those with observations they may
     * see (historical data stays reachable after assignments change), plus
     * active indicators in their scope — every one with data.view_all_units,
     * otherwise only their unit's assignments.
     */
    public function scopeReportableFor(Builder $query, User $user): Builder
    {
        // Every category (INM, IMP RS, IMP Unit): a report needs a form, not input access.
        // Only categories of the user's view permissions (inm.view / imp.view).
        $query->whereHas('category', fn (Builder $c) => $c->whereIn('code', $user->categoryCodes('view')));

        return $query->withReportForm()->where(function (Builder $q) use ($user) {
            $q->whereHas('observations', fn (Builder $o) => $o->visibleTo($user));

            if ($user->canViewAllUnits()) {
                $q->orWhere('is_active', true);
            } elseif ($user->unit_id) {
                $q->orWhere(fn (Builder $a) => $a->where('is_active', true)->assignedTo($user->unit_id));
            }
        });
    }
}

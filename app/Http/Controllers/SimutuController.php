<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SimutuController extends Controller
{
    /**
     * Dashboard: indicators of every category (INM, IMP RS, IMP Unit), the
     * units they are mapped to (indicator_unit), and what was reported in
     * the selected period.
     *
     * Everything on the page comes from one set of filters: the period, and
     * a unit (chosen by Admin / PIC Mutu, fixed to their own for PIC Unit).
     * Only observation counts are shown; achievement percentages are not,
     * because no calculation formula has been defined for any indicator.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ], [], [
            'start_date' => 'Tanggal Mulai',
            'end_date' => 'Tanggal Akhir',
            'unit_id' => 'Unit',
        ]);

        $filters = [
            'start_date' => $validated['start_date'] ?? now()->startOfMonth()->toDateString(),
            'end_date' => $validated['end_date'] ?? now()->toDateString(),
            'unit_id' => $user->canViewAllUnits() ? ($validated['unit_id'] ?? null) : null,
        ];

        // The unit everything is narrowed to: the filter for Admin / PIC Mutu
        // (null = all units), always their own unit for PIC Unit.
        $scoped = ! $user->canViewAllUnits() || $filters['unit_id'] !== null;
        $scopeUnitId = $user->canViewAllUnits() ? $filters['unit_id'] : (int) $user->unit_id;

        // One grouped query feeds every number on the page, so the cards,
        // the indicator list and the unit list can never disagree.
        $counts = Observation::query()
            ->visibleTo($user)
            ->filter($filters)
            ->selectRaw('unit_id, indicator_id, COUNT(*) AS total')
            ->groupBy('unit_id', 'indicator_id')
            ->get();

        $byIndicator = $counts->groupBy('indicator_id');
        $byUnit = $counts->groupBy('unit_id');

        // indicator_unit, limited to the unit in scope.
        $mappings = DB::table('indicator_unit')
            ->when($scoped, fn ($query) => $query->where('unit_id', $scopeUnitId))
            ->get(['indicator_id', 'unit_id']);

        $unitsByIndicator = $mappings->groupBy('indicator_id')->map->pluck('unit_id');
        $indicatorsByUnit = $mappings->groupBy('unit_id')->map->pluck('indicator_id');

        $indicators = $this->indicators($user, $scoped, $byIndicator, $unitsByIndicator);
        $activeIndicatorIds = $indicators->where('is_active', true)->pluck('id');
        $unitRows = $this->unitRows($scoped, $scopeUnitId, $byUnit, $indicatorsByUnit, $activeIndicatorIds);

        return view('dashboard', [
            'filters' => $filters,
            'scoped' => $scoped,
            'scopeUnit' => $scoped ? Unit::find($scopeUnitId) : null,
            'total' => (int) $counts->sum('total'),
            'activeIndicators' => $scoped
                ? $indicators->where('is_active', true)->where('assigned', true)->count()
                : $activeIndicatorIds->count(),
            'totalUnits' => $scoped ? $unitRows->count() : Unit::where('is_active', true)->count(),
            'reportingUnits' => $byUnit->count(),
            'categories' => IndicatorCategory::orderBy('id')->get(),
            'indicators' => $indicators,
            'unitRows' => $unitRows,
            'trend' => $this->monthlyTrend($user, $filters),
            'units' => $user->canViewAllUnits() ? Unit::orderBy('name')->get() : collect(),
            'missingUnit' => ! $user->canViewAllUnits() && $user->activeUnit() === null,
            ...$this->inputState($user),
        ]);
    }

    /**
     * For PIC Unit: the mapped INM / IMP RS indicators they can record now
     * (an "Input Observasi" button) and those still waiting for a published
     * form. Empty for every other role, whose dashboard is unchanged.
     *
     * @return array{inputIds: Collection<int, int>, unpublishedIds: Collection<int, int>}
     */
    private function inputState(User $user): array
    {
        $unit = ! $user->canViewAllUnits() && $user->can('create', Observation::class) ? $user->activeUnit() : null;

        if ($unit === null) {
            return ['inputIds' => collect(), 'unpublishedIds' => collect()];
        }

        $mapped = Indicator::inputCategory()->where('is_active', true)->assignedTo($unit->id)->creatableBy($user);

        return [
            'inputIds' => (clone $mapped)->whereHas('publishedFormVersion')->pluck('id'),
            'unpublishedIds' => (clone $mapped)->whereDoesntHave('publishedFormVersion')->pluck('id'),
        ];
    }

    /**
     * Indicators to list, each with its numbers for the scope.
     *
     * Listed: active indicators (when a unit is in scope, only those mapped
     * to it), plus any indicator that has observations in the period, so
     * recorded data never disappears when a mapping or status changes.
     * An indicator without observations is never shown as "reported".
     *
     * @param  Collection<int, Collection>  $byIndicator  observation counts grouped by indicator
     * @param  Collection<int, Collection<int, int>>  $unitsByIndicator  mapped unit IDs per indicator
     * @return Collection<int, Indicator>
     */
    private function indicators(User $user, bool $scoped, Collection $byIndicator, Collection $unitsByIndicator): Collection
    {
        // Only categories the user may view (inm.view / imp.view).
        return Indicator::with('category')
            ->whereHas('category', fn ($q) => $q->whereIn('code', $user->categoryCodes('view')))
            ->orderBy('indicator_category_id')
            ->orderBy('name')
            ->get()
            ->each(function (Indicator $indicator) use ($byIndicator, $unitsByIndicator) {
                $rows = $byIndicator->get($indicator->id, collect());
                $mapped = $unitsByIndicator->get($indicator->id, collect());

                $indicator->setAttribute('total', (int) $rows->sum('total'));
                $indicator->setAttribute('assigned', $mapped->isNotEmpty());
                $indicator->setAttribute('assigned_units_count', $mapped->count());
                // Mapped units that reported, so "3 dari 5 unit" never counts
                // a unit that reported without being mapped.
                $indicator->setAttribute('reporting_units_count', $rows->pluck('unit_id')->intersect($mapped)->count());
            })
            ->filter(fn (Indicator $indicator) => $indicator->total > 0
                || ($indicator->is_active && (! $scoped || $indicator->assigned)))
            ->values();
    }

    /**
     * Units to list with their mapped indicators and observations.
     *
     * Listed: active units (only the one in scope when there is one), plus
     * any unit that has observations in the period.
     *
     * @param  Collection<int, Collection>  $byUnit  observation counts grouped by unit
     * @param  Collection<int, Collection<int, int>>  $indicatorsByUnit  mapped indicator IDs per unit
     * @param  Collection<int, int>  $activeIndicatorIds
     * @return Collection<int, array{unit: Unit, indicators: int, total: int, reported: bool}>
     */
    private function unitRows(bool $scoped, ?int $scopeUnitId, Collection $byUnit, Collection $indicatorsByUnit, Collection $activeIndicatorIds): Collection
    {
        return Unit::query()
            ->when($scoped, fn ($query) => $query->whereKey($scopeUnitId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Unit $unit) => $unit->is_active || $byUnit->has($unit->id))
            ->map(function (Unit $unit) use ($byUnit, $indicatorsByUnit, $activeIndicatorIds) {
                $total = (int) $byUnit->get($unit->id, collect())->sum('total');

                return [
                    'unit' => $unit,
                    // Active indicators this unit is responsible for.
                    'indicators' => $indicatorsByUnit->get($unit->id, collect())->intersect($activeIndicatorIds)->count(),
                    'total' => $total,
                    'reported' => $total > 0,
                ];
            })
            ->values();
    }

    /**
     * Observation counts for the six months ending at the filter's end date.
     *
     * @return list<array{label: string, total: int}>
     */
    private function monthlyTrend(User $user, array $filters): array
    {
        $end = Carbon::parse($filters['end_date'])->endOfMonth();
        $start = $end->copy()->subMonthsNoOverflow(5)->startOfMonth();

        $counts = Observation::query()
            ->visibleTo($user)
            ->filter(['unit_id' => $filters['unit_id'], 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()])
            ->selectRaw("DATE_FORMAT(observation_date, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $months = [];
        for ($month = $start->copy(); $month <= $end; $month->addMonthNoOverflow()) {
            $months[] = [
                'label' => $month->translatedFormat('M Y'),
                'total' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $months;
    }
}

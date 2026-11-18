<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validated filters of the observation report: one category, exactly one
 * indicator of that category, a date range and one unit or every unit.
 *
 * A report never mixes indicators: there is no "all indicators" choice, and
 * the indicator must belong to the chosen category and be one the user may
 * report on (Indicator::reportableFor). PIC Unit accounts are pinned to
 * their own unit; any other unit is refused, not silently replaced.
 */
class ReportFilters
{
    /** Category tabs: query value => category code and label. */
    public const CATEGORIES = [
        'inm' => ['code' => 'INM', 'label' => 'INM'],
        'imp-rs' => ['code' => 'IMP_RS', 'label' => 'IMP RS'],
        'imp-unit' => ['code' => 'IMP_UNIT', 'label' => 'IMP Unit'],
    ];

    /** Unit choice meaning every unit the user may see. */
    public const ALL_UNITS = 'semua';

    private function __construct(
        public readonly string $category,
        public readonly ?Indicator $indicator,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?Unit $unit,
    ) {}

    /**
     * On the report page the indicator may still be missing (the user has
     * not chosen one yet) and the dates default to this month. An export
     * needs every filter.
     */
    public static function fromRequest(Request $request, bool $complete = false): self
    {
        $user = $request->user();

        if (in_array($request->query('unit_id'), [self::ALL_UNITS, ''], true)) {
            $request->query->remove('unit_id');
        }

        // Older links (dashboard) name only the indicator: its own category is used.
        $category = $request->query('kategori');
        if ($category === null && is_numeric($request->query('indicator_id'))) {
            $code = Indicator::with('category')->find((int) $request->query('indicator_id'))?->category?->code;
            $category = collect(self::CATEGORIES)->search(fn (array $c) => $c['code'] === $code) ?: null;
        }
        $category ??= array_key_first(self::CATEGORIES);

        $code = self::CATEGORIES[$category]['code'] ?? null;
        $indicatorIds = $code ? self::indicators($user, $category)->pluck('id')->all() : [];
        $required = $complete ? 'required' : 'nullable';

        $validated = validator(
            [...$request->query(), 'kategori' => $category],
            [
                'kategori' => ['required', Rule::in(array_keys(self::CATEGORIES))],
                'indicator_id' => [$required, 'integer', Rule::in($indicatorIds)],
                'start_date' => [$required, 'date_format:Y-m-d'],
                'end_date' => [$required, 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'unit_id' => ['nullable', 'integer', $user->canViewAllUnits()
                    ? Rule::exists('units', 'id')
                    : Rule::in(array_filter([$user->unit_id]))],
            ],
            [
                'kategori.*' => 'Pilih kategori INM, IMP RS, atau IMP Unit.',
                'indicator_id.required' => 'Pilih satu indikator untuk laporan.',
                'indicator_id.in' => 'Indikator tidak tersedia pada kategori yang dipilih.',
                'unit_id.in' => 'Anda hanya dapat melihat laporan unit Anda sendiri.',
            ],
            [
                'start_date' => 'Tanggal Mulai',
                'end_date' => 'Tanggal Akhir',
                'indicator_id' => 'Indikator',
                'unit_id' => 'Unit',
            ],
        )->validate();

        $today = now()->toDateString();
        $start = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $end = $validated['end_date'] ?? max($today, $start);

        if ($start > $end) {
            $start = Carbon::parse($end)->startOfMonth()->toDateString();
        }

        $unitId = $user->canViewAllUnits() ? ($validated['unit_id'] ?? null) : $user->unit_id;

        return new self(
            $category,
            isset($validated['indicator_id']) ? Indicator::with('category')->find($validated['indicator_id']) : null,
            $start,
            $end,
            $unitId ? Unit::find($unitId) : null,
        );
    }

    /**
     * Indicators of the category the user may report on, by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Indicator>
     */
    public static function indicators(User $user, string $category): \Illuminate\Database\Eloquent\Collection
    {
        return Indicator::reportableFor($user)
            ->inCategory(self::CATEGORIES[$category]['code'])
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * Filters for Observation::scopeFilter.
     *
     * @return array<string, mixed>
     */
    public function scope(): array
    {
        return array_filter([
            'indicator_id' => $this->indicator?->id,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'unit_id' => $this->unit?->id,
        ]);
    }

    /**
     * Query string that reproduces this report (links, export).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return array_filter([
            'kategori' => $this->category,
            'indicator_id' => $this->indicator?->id,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'unit_id' => $this->unit?->id,
        ]);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'];
    }
}

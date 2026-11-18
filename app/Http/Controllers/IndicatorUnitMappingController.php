<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-only management of which units each indicator is mapped to
 * (the `indicator_unit` pivot). This is the only place mappings are managed.
 *
 * For IMP RS and IMP Unit indicators a mapping is administrative only: no
 * access rule reads it yet. For INM indicators the pivot also decides which
 * units may record the indicator (Indicator::scopeAssignedTo), so changing
 * an INM mapping here changes who can enter observations for it.
 */
class IndicatorUnitMappingController extends Controller
{
    private const CATEGORIES = ['inm', 'imp-rs', 'imp-unit'];

    private const STATUSES = ['mapped', 'unmapped'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ], [], ['q' => 'Pencarian', 'category' => 'Kategori', 'status' => 'Status mapping']);

        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');

        $indicators = Indicator::query()
            ->with(['category', 'unit', 'assignedUnits' => fn ($query) => $query->orderBy('units.name')])
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($filters['category'] ?? null, fn (Builder $query, string $slug) => $query->whereHas('category', fn (Builder $c) => $c->where('slug', $slug)))
            ->when(($filters['status'] ?? null) === 'mapped', fn (Builder $query) => $query->has('assignedUnits'))
            ->when(($filters['status'] ?? null) === 'unmapped', fn (Builder $query) => $query->doesntHave('assignedUnits'))
            ->orderBy('indicator_category_id')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('indicator-unit-mappings.index', [
            'indicators' => $indicators,
            'filters' => $filters,
            'categories' => IndicatorCategory::whereIn('slug', self::CATEGORIES)->orderBy('id')->get(),
            'totals' => [
                'all' => Indicator::count(),
                'mapped' => Indicator::has('assignedUnits')->count(),
                'unmapped' => Indicator::doesntHave('assignedUnits')->count(),
            ],
        ]);
    }

    public function edit(Indicator $indicator): View
    {
        $indicator->load(['category', 'unit']);
        $mappedIds = $indicator->assignedUnits()->pluck('units.id');

        return view('indicator-unit-mappings.edit', [
            'indicator' => $indicator,
            'mappedIds' => $mappedIds,
            // Inactive units are only listed while they are still mapped, so
            // they can be removed but not newly added.
            'units' => Unit::where('is_active', true)->orWhereIn('id', $mappedIds)->orderBy('name')->get(),
        ]);
    }

    /**
     * Replace the indicator's unit mappings with the submitted selection.
     */
    public function update(Request $request, Indicator $indicator): RedirectResponse
    {
        $current = $indicator->assignedUnits()->pluck('units.id');
        $allowed = Unit::where('is_active', true)->pluck('id')->merge($current)->unique();

        $validated = $request->validate([
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'distinct', Rule::in($allowed->all())],
        ], [
            'unit_ids.*.in' => 'Unit yang dipilih tidak ditemukan atau tidak aktif.',
        ], [
            'unit_ids' => 'Unit',
            'unit_ids.*' => 'Unit',
        ]);

        $selected = collect($validated['unit_ids'] ?? [])->map(fn ($id) => (int) $id);

        // sync() only inserts and deletes the difference, so existing rows
        // (and their timestamps) stay, and a pair can never be stored twice.
        $changes = DB::transaction(fn () => $indicator->assignedUnits()->sync($selected->all()));

        $added = count($changes['attached']);
        $removed = count($changes['detached']);

        return redirect()
            ->route('indicator-unit-mappings.index', $request->query())
            ->with('success', $added + $removed === 0
                ? "Mapping unit untuk \"{$indicator->name}\" tidak berubah."
                : "Mapping unit untuk \"{$indicator->name}\" diperbarui: {$added} ditambahkan, {$removed} dihapus.");
    }

    /**
     * Remove one unit from an indicator.
     */
    public function destroy(Indicator $indicator, Unit $unit): RedirectResponse
    {
        $removed = $indicator->assignedUnits()->detach($unit->id);

        return back()->with(
            $removed ? 'success' : 'error',
            $removed
                ? "Unit {$unit->name} dihapus dari mapping \"{$indicator->name}\"."
                : "Unit {$unit->name} tidak terdaftar pada mapping indikator ini.",
        );
    }
}

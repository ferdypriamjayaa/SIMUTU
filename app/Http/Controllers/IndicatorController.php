<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class IndicatorController extends Controller
{
    public function show(string $category): View
    {
        $indicatorCategory = IndicatorCategory::with(['indicators.unit', 'indicators.publishedFormVersion', 'indicators.draftFormVersion'])
            ->where('slug', $category)
            ->firstOrFail();

        return view('indicators.show', [
            'category' => $indicatorCategory,
            'categoryName' => $indicatorCategory->name,
            'indicators' => $indicatorCategory->indicators,
        ]);
    }

    public function create(string $category): View
    {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->firstOrFail();

        abort_unless(
            in_array($indicatorCategory->code, ['IMP_RS', 'IMP_UNIT']),
            403
        );

        $units = Unit::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('indicators.create', [
            'category' => $indicatorCategory,
            'categoryName' => $indicatorCategory->name,
            'units' => $units,
        ]);
    }

    public function store(
        Request $request,
        string $category
    ): RedirectResponse {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->whereIn('code', ['IMP_RS', 'IMP_UNIT'])
            ->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('indicators', 'name')
                    ->where(
                        'indicator_category_id',
                        $indicatorCategory->id
                    ),
            ],
            'unit_id' => [
                Rule::requiredIf($indicatorCategory->code === 'IMP_UNIT'),
                'nullable',
                'integer',
                Rule::exists('units', 'id')->where('is_active', true),
            ],
        ], [], [
            'name' => 'Nama indikator',
            'unit_id' => 'Unit penanggung jawab',
        ]);

        Indicator::create([
            'indicator_category_id' => $indicatorCategory->id,
            'unit_id' => $indicatorCategory->code === 'IMP_UNIT' ? $validated['unit_id'] : null,
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return redirect()
            ->route('indicators.show', [
                'category' => $indicatorCategory->slug,
            ])
            ->with('success', 'Indikator berhasil ditambahkan.');
    }

    public function edit(string $category, Indicator $indicator): View
    {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->whereIn('code', ['IMP_RS', 'IMP_UNIT'])
            ->firstOrFail();

        abort_unless(
            $indicator->indicator_category_id === $indicatorCategory->id,
            404
        );

        return view('indicators.edit', [
            'category' => $indicatorCategory,
            'indicator' => $indicator,
        ]);
    }

    public function update(
        Request $request,
        string $category,
        Indicator $indicator
    ): RedirectResponse {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->whereIn('code', ['IMP_RS', 'IMP_UNIT'])
            ->firstOrFail();

        abort_unless(
            $indicator->indicator_category_id === $indicatorCategory->id,
            404
        );


        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('indicators', 'name')
                    ->where(
                        'indicator_category_id',
                        $indicatorCategory->id
                    )
                    ->ignore($indicator->id),
            ],
        ]);


        $indicator->update([
            'name' => $validated['name'],
        ]);

        return redirect()
            ->route('indicators.show', ['category' => $indicatorCategory->slug])
            ->with('success', 'Indikator berhasil diperbarui.');
    }

    public function toggleStatus(
        string $category,
        Indicator $indicator
    ): RedirectResponse {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->whereIn('code', ['IMP_RS', 'IMP_UNIT'])
            ->firstOrFail();

        abort_unless(
            $indicator->indicator_category_id === $indicatorCategory->id,
            404
        );

        $indicator->update([
            'is_active' => ! $indicator->is_active,
        ]);

        return redirect()
            ->route('indicators.show', ['category' => $indicatorCategory->slug])
            ->with('success', 'Status indikator berhasil diperbarui.');
    }

    public function destroy(
        string $category,
        Indicator $indicator
    ): RedirectResponse {
        $indicatorCategory = IndicatorCategory::where('slug', $category)
            ->whereIn('code', ['IMP_RS', 'IMP_UNIT'])
            ->firstOrFail();

        abort_unless(
            $indicator->indicator_category_id === $indicatorCategory->id,
            404
        );

        if ($indicator->observations()->exists()) {
            return redirect()
                ->route('indicators.show', ['category' => $indicatorCategory->slug])
                ->with('error', 'Indikator yang sudah memiliki data observasi tidak dapat dihapus. Nonaktifkan indikator sebagai gantinya.');
        }

        $indicator->delete();

        return redirect()
            ->route('indicators.show', [
                'category' => $indicatorCategory->slug,
            ])
            ->with('success', 'Indikator berhasil dihapus.');
    }
}

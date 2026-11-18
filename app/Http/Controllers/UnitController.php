<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(): View
    {
        $units = Unit::orderBy('name')->get();

        return view('units.index', [
            'units' => $units,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:units,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Unit::create([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return redirect()
            ->route('units.index')
            ->with('success', 'Unit berhasil ditambahkan.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('units', 'code')->ignore($unit->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $unit->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
        ]);

        return redirect()
            ->route('units.index')
            ->with('success', 'Unit berhasil diperbarui.');
    }

    public function toggleStatus(Unit $unit): RedirectResponse
    {
        $unit->update([
            'is_active' => ! $unit->is_active,
        ]);

        return redirect()
            ->route('units.index')
            ->with('success', 'Status unit berhasil diperbarui.');
    }

    /**
     * Only units nothing depends on can be deleted; their indicator mappings
     * go with them (cascade). Units with history should be deactivated instead.
     */
    public function destroy(Unit $unit): RedirectResponse
    {
        $blockers = array_filter([
            'data observasi' => $unit->observations()->count(),
            'akun pengguna' => $unit->users()->count(),
            'indikator IMP Unit' => $unit->indicators()->count(),
        ]);

        if ($blockers !== []) {
            $list = collect($blockers)->map(fn ($count, $label) => "{$count} {$label}")->join(', ', ' dan ');

            return redirect()
                ->route('units.index')
                ->with('error', "Unit {$unit->name} tidak dapat dihapus karena masih memiliki {$list}. Pindahkan akun pengguna ke unit lain, atau nonaktifkan unit ini sebagai gantinya.");
        }

        $unit->delete();

        return redirect()
            ->route('units.index')
            ->with('success', "Unit {$unit->name} berhasil dihapus.");
    }
}

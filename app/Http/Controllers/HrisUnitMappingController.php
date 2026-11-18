<?php

namespace App\Http\Controllers;

use App\Models\HrisUnitMapping;
use App\Models\Unit;
use App\Services\DokterException;
use App\Services\DokterService;
use App\Services\EmployeeService;
use App\Services\HrisException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-only: which HRIS division/department placements belong to which
 * SIMUTU unit. Every pairing is chosen by the Admin; nothing is matched
 * automatically. Access is enforced by the `role:admin` route middleware.
 */
class HrisUnitMappingController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DokterService $doctors,
    ) {}

    public function index(Request $request): View
    {
        $companies = $this->employees->companyCodes();
        $company = $request->query('company');
        $company = is_string($company) && array_key_exists($company, $companies) ? $company : array_key_first($companies);

        $placements = [];
        $error = null;

        if (! $this->employees->isConfigured()) {
            $error = 'Integrasi HRIS belum dikonfigurasi. Isi HRIS_API_URL, HRIS_API_USERNAME, HRIS_API_PASSWORD, dan HRIS_COMPANY_CODES pada file .env.';
        } elseif ($company !== null) {
            try {
                $placements = $this->employees->placements($company);
            } catch (HrisException $exception) {
                $error = $exception->getMessage();
            }
        }

        $mappings = HrisUnitMapping::with('unit')
            ->where('company_code', $company)
            ->get();

        $byPlacement = $mappings->groupBy(fn (HrisUnitMapping $mapping) => $mapping->division."\n".$mapping->department);
        $known = collect($placements)->map(fn ($p) => $p['division']."\n".$p['department']);

        return view('hris-mappings.index', [
            'companies' => $companies,
            'company' => $company,
            'placements' => $placements,
            'byPlacement' => $byPlacement,
            // Saved pairings HRIS no longer reports (renamed or emptied placements).
            'orphans' => $mappings->reject(fn ($m) => $known->contains($m->division."\n".$m->department))->values(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
            'error' => $error,
            'unmappedUnits' => Unit::where('is_active', true)->doesntHave('hrisMappings')->orderBy('name')->pluck('name'),
            'doctorApi' => $this->doctorApi(),
        ]);
    }

    /**
     * Map one HRIS placement to a unit. The mapping page submits this in
     * place (JSON); without JavaScript it is a plain form post and redirects.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $companies = array_keys($this->employees->companyCodes());

        // A failed validation answers 422 JSON on its own when JSON is expected.
        $validated = $request->validate([
            'company_code' => ['required', 'string', Rule::in($companies)],
            'division' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('is_active', true)],
        ], [
            'unit_id.required' => 'Pilih unit SIMUTU.',
        ], ['unit_id' => 'Unit', 'company_code' => 'Perusahaan']);

        $division = filled($validated['division'] ?? null) ? $validated['division'] : null;
        $department = filled($validated['department'] ?? null) ? $validated['department'] : null;
        $company = $validated['company_code'];

        if ($division === null && $department === null) {
            return $this->storeFailed($request, $company, 'Karyawan yang belum ditempatkan pada divisi maupun departemen tidak dapat dipetakan ke unit.');
        }

        // Only placements HRIS actually reports can be mapped: no typing guesses.
        try {
            $placements = $this->employees->placements($company);
        } catch (HrisException $exception) {
            return $this->storeFailed($request, $company, $exception->getMessage());
        }

        if (! collect($placements)->contains(fn ($p) => $p['division'] === $division && $p['department'] === $department)) {
            return $this->storeFailed($request, $company, 'Departemen tersebut tidak ditemukan di HRIS. Muat ulang data HRIS lalu coba lagi.');
        }

        $mapping = HrisUnitMapping::firstOrCreate([
            'unit_id' => $validated['unit_id'],
            'company_code' => $company,
            'division' => $division,
            'department' => $department,
        ]);

        $label = $division ?? $department;
        $message = $mapping->wasRecentlyCreated
            ? "\"{$label}\" dipetakan ke unit {$mapping->unit->name}."
            : "\"{$label}\" sudah dipetakan ke unit {$mapping->unit->name}.";

        if (! $request->expectsJson()) {
            return redirect()->route('hris-mappings.index', ['company' => $company])->with('success', $message);
        }

        // What the page needs to update the placement and the summaries in place.
        $mapped = HrisUnitMapping::with('unit')
            ->where(['company_code' => $company, 'division' => $division, 'department' => $department])
            ->get();
        $mappedKeys = HrisUnitMapping::where('company_code', $company)->get()
            ->map(fn (HrisUnitMapping $m) => $m->division."\n".$m->department)
            ->unique();
        $mappedCount = collect($placements)->filter(fn ($p) => $mappedKeys->contains($p['division']."\n".$p['department']))->count();
        $unmappedUnits = Unit::where('is_active', true)->doesntHave('hrisMappings')->orderBy('name')->pluck('name');

        return response()->json([
            'message' => $message,
            'created' => $mapping->wasRecentlyCreated,
            'mapped_html' => view('hris-mappings.partials.mapped-units', ['mapped' => $mapped])->render(),
            'mapped_unit_ids' => $mapped->pluck('unit_id')->values(),
            'summary' => count($placements).' departemen/divisi · '.$mappedCount.' sudah dipetakan',
            'unmapped_html' => view('hris-mappings.partials.unmapped-units', ['unmappedUnits' => $unmappedUnits])->render(),
            'unmapped_count' => $unmappedUnits->count(),
        ]);
    }

    private function storeFailed(Request $request, string $company, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : redirect()->route('hris-mappings.index', ['company' => $company])->with('error', $message);
    }

    public function destroy(HrisUnitMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return redirect()
            ->route('hris-mappings.index', ['company' => $mapping->company_code])
            ->with('success', 'Pemetaan "'.($mapping->division ?? $mapping->department)."\" dari unit {$mapping->unit?->name} dihapus.");
    }

    /**
     * Fetch the employee list again instead of waiting for the cache to expire.
     */
    public function refresh(Request $request): RedirectResponse
    {
        $validated = $request->validate(['company_code' => ['required', Rule::in(array_keys($this->employees->companyCodes()))]]);

        try {
            $this->employees->employees($validated['company_code'], refresh: true);
            $result = ['success', 'Data karyawan HRIS berhasil dimuat ulang.'];
        } catch (HrisException $exception) {
            $result = ['error', $exception->getMessage()];
        }

        return redirect()->route('hris-mappings.index', ['company' => $validated['company_code']])->with(...$result);
    }

    /**
     * Fetch the doctor list again instead of waiting for the cache to expire.
     */
    public function refreshDoctors(Request $request): RedirectResponse
    {
        try {
            $count = count($this->doctors->refresh());
            $result = ['success', "Data dokter berhasil dimuat ulang: {$count} dokter aktif."];
        } catch (DokterException $exception) {
            $result = ['error', $exception->getMessage()];
        }

        $company = $request->input('company');

        return redirect()
            ->route('hris-mappings.index', is_string($company) && array_key_exists($company, $this->employees->companyCodes()) ? ['company' => $company] : [])
            ->with(...$result);
    }

    /**
     * Status of the Doctor API for the mapping page: doctors per hospital,
     * with the HRIS company each hospital belongs to.
     *
     * @return array{configured: bool, hospitals: list<array{name: string, doctors: int, company: ?string}>, error: ?string}
     */
    private function doctorApi(): array
    {
        if (! $this->doctors->isConfigured()) {
            return ['configured' => false, 'hospitals' => [], 'error' => 'Integrasi API Dokter belum dikonfigurasi. Isi DOKTER_API_URL, DOKTER_API_USERNAME, dan DOKTER_API_PASSWORD pada file .env.'];
        }

        try {
            $companies = array_flip(DokterService::HOSPITALS);
            $hospitals = [];

            foreach ($this->doctors->summary() as $name => $count) {
                $hospitals[] = ['name' => $name, 'doctors' => $count, 'company' => $companies[$name] ?? null];
            }

            return ['configured' => true, 'hospitals' => $hospitals, 'error' => null];
        } catch (DokterException $exception) {
            return ['configured' => true, 'hospitals' => [], 'error' => $exception->getMessage()];
        }
    }
}

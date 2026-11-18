<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Models\Unit;
use App\Services\DokterException;
use App\Services\DokterService;
use App\Services\EmployeeService;
use App\Services\HrisException;
use App\Support\PickerSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Server-side search for the observation form's searchable dropdowns, so the
 * page never carries the whole employee or doctor list.
 *
 * Returns the same data the dropdowns showed before (employee: id and name;
 * doctor: id, name, specialty, hospital), one page at a time. The chosen
 * identifier is still resolved and checked again on save (EmployeePicker,
 * DoctorPicker), so this endpoint grants nothing by itself.
 *
 * Who may search: whoever may fill in the form. A new observation is entered
 * for the user's own active unit; when editing, for the observation's unit.
 */
class ObservationLookupController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DokterService $doctors,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'observation' => ['nullable', 'integer'],
        ]);

        $unit = $this->unit($request);
        $query = (string) $request->query('q', '');
        $page = $request->integer('page', 1);

        try {
            $result = $source === 'employees'
                ? $this->employees($unit, $query, $page)
                : $this->doctors($unit, $query, $page);
        } catch (HrisException|DokterException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json($result);
    }

    /** The unit the form is filled in for, after checking the user may fill it in. */
    private function unit(Request $request): ?Unit
    {
        if ($request->filled('observation')) {
            $observation = Observation::with('unit')->findOrFail($request->integer('observation'));
            Gate::authorize('update', $observation);

            return $observation->unit;
        }

        Gate::authorize('create', Observation::class);

        return $request->user()->activeUnit();
    }

    /** @return array{data: list<array{value: string, label: string}>, page: int, next_page: ?int, total: int} */
    private function employees(?Unit $unit, string $query, int $page): array
    {
        if (! $this->employees->isConfigured()) {
            throw new HrisException('Integrasi HRIS belum dikonfigurasi.');
        }

        // An unmapped unit has no HRIS staff; HRIS is not called.
        $list = $unit !== null && $unit->hrisMappings()->exists() ? $this->employees->forUnit($unit) : [];
        $result = PickerSearch::page($list, $query, $page, fn (array $employee) => $employee['name']);

        // Name only: no division, department, company or NIP.
        $result['data'] = array_map(fn (array $employee) => ['value' => $employee['id'], 'label' => $employee['name']], $result['data']);

        return $result;
    }

    /** @return array{data: list<array{value: string, label: string, description: ?string, group: string}>, page: int, next_page: ?int, total: int} */
    private function doctors(?Unit $unit, string $query, int $page): array
    {
        if (! $this->doctors->isConfigured()) {
            throw new DokterException('Integrasi API Dokter belum dikonfigurasi.');
        }

        // The unit's own hospital first, as in the full list.
        $companies = $unit ? $unit->hrisMappings()->distinct()->pluck('company_code')->all() : [];
        $list = [];

        foreach ($this->doctors->byHospital($companies) as $hospital => $doctors) {
            foreach ($doctors as $doctor) {
                $list[] = [...$doctor, 'group' => $hospital];
            }
        }

        $result = PickerSearch::page($list, $query, $page, fn (array $doctor) => $doctor['name'].' '.($doctor['specialty'] ?? ''));
        $result['data'] = array_map(fn (array $doctor) => [
            'value' => $doctor['id'],
            'label' => $doctor['name'],
            'description' => $doctor['specialty'],
            'group' => $doctor['group'],
        ], $result['data']);

        return $result;
    }
}

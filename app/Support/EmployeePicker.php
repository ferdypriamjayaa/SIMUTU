<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Unit;
use App\Services\EmployeeService;
use App\Services\HrisException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Offers the unit's HRIS employees as a dropdown for the "officer" fields
 * of INM observation forms.
 *
 * What is stored does not change: the field still holds the officer's name.
 * The dropdown submits an opaque employee identifier in a companion input
 * ("<key>__employee"); it is resolved back to the name on the server, against
 * the same unit's list, so a name outside the unit cannot be injected that
 * way. Choosing "isi manual" keeps the plain text input, which is also what
 * everyone gets whenever HRIS is unavailable or the unit is not mapped.
 */
class EmployeePicker
{
    /** INM fields that name the observed officer. */
    public const FIELDS = ['officer_name'];

    public const SUFFIX = '__employee';

    public const MANUAL = '__manual';

    public function __construct(private readonly EmployeeService $employees) {}

    /**
     * Dropdown data per field key for the form, or an empty array when the
     * form has no officer field. Per field: employees (id and name only; no
     * division, department or company) or null, and a notice explaining why
     * there is no dropdown.
     *
     * @return array<string, array{employees: list<array{id: string, name: string}>|null, notice: ?string}>
     */
    public function forForm(Indicator $indicator, IndicatorFormVersion $version, ?Unit $unit): array
    {
        $keys = $this->fieldKeys($indicator, $version);

        if ($keys === [] || ! $this->employees->isConfigured()) {
            return [];
        }

        $employees = null;
        $notice = null;

        if ($unit === null || ! $unit->hrisMappings()->exists()) {
            $notice = 'Daftar karyawan HRIS belum tersedia karena unit ini belum dipetakan ke departemen HRIS. Ketik nama petugas.';
        } else {
            try {
                $employees = array_map(
                    fn (array $employee) => ['id' => $employee['id'], 'name' => $employee['name']],
                    $this->employees->forUnit($unit),
                );
                $notice = $employees === [] ? 'Tidak ada karyawan aktif di departemen HRIS unit ini. Ketik nama petugas.' : null;
                $employees = $employees ?: null;
            } catch (HrisException $exception) {
                $notice = $exception->getMessage().' Ketik nama petugas.';
            }
        }

        return array_fill_keys($keys, ['employees' => $employees, 'notice' => $notice]);
    }

    /**
     * Replace each chosen employee identifier with the employee's name before
     * the form is validated. Manual entries and plain text are left as typed.
     *
     * @throws ValidationException
     */
    public function resolve(Request $request, Indicator $indicator, IndicatorFormVersion $version, ?Unit $unit): void
    {
        $chosen = [];

        foreach ($this->fieldKeys($indicator, $version) as $key) {
            $value = $request->input($key.self::SUFFIX);

            if (is_string($value) && $value !== '' && $value !== self::MANUAL) {
                $chosen[$key] = $value;
            }
        }

        if ($chosen === []) {
            return;
        }

        $failed = fn (string $message) => ValidationException::withMessages(array_fill_keys(array_keys($chosen), $message));

        if ($unit === null) {
            throw $failed('Unit observasi tidak dikenali.');
        }

        try {
            $byId = array_column($this->employees->forUnit($unit), 'name', 'id');
        } catch (HrisException $exception) {
            throw $failed($exception->getMessage().' Pilih "Lainnya — ketik manual" untuk mengetik nama petugas.');
        }

        $names = [];

        foreach ($chosen as $key => $id) {
            if (! isset($byId[$id])) {
                throw $failed('Petugas yang dipilih tidak terdaftar pada unit ini. Pilih ulang dari daftar.');
            }

            $names[$key] = $byId[$id];
        }

        $request->merge($names);
    }

    /**
     * Officer fields this form has: INM forms only, active text fields.
     *
     * @return list<string>
     */
    private function fieldKeys(Indicator $indicator, IndicatorFormVersion $version): array
    {
        if ($indicator->form_type === null) {
            return [];
        }

        return $version->activeFields()
            ->filter(fn (IndicatorField $field) => in_array($field->key, self::FIELDS, true) && $field->type === IndicatorField::TYPE_TEXT)
            ->pluck('key')
            ->values()
            ->all();
    }
}

<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Unit;
use App\Services\DokterException;
use App\Services\DokterService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Offers the active doctors from the Doctor API as a dropdown for the
 * "doctor" fields of INM observation forms.
 *
 * What is stored does not change: the field still holds the doctor's name.
 * The dropdown submits an opaque doctor identifier in a companion input
 * ("<key>__doctor"); it is resolved back to the name on the server, so only
 * names the API reports can be injected that way. Choosing "isi manual"
 * keeps the plain text input, which is also what everyone gets whenever the
 * API is unavailable.
 */
class DoctorPicker
{
    /** INM fields that name the observed doctor. */
    public const FIELDS = ['doctor_name'];

    public const SUFFIX = '__doctor';

    public const MANUAL = '__manual';

    public function __construct(private readonly DokterService $doctors) {}

    /**
     * Dropdown data per field key, or an empty array when the form has no
     * doctor field or the API is not configured. Per field: doctors grouped
     * by hospital (id, name, specialty) or null, and a notice explaining why
     * there is no dropdown.
     *
     * @return array<string, array{groups: array<string, list<array{id: string, name: string, specialty: ?string}>>|null, notice: ?string}>
     */
    public function forForm(Indicator $indicator, IndicatorFormVersion $version, ?Unit $unit): array
    {
        $keys = $this->fieldKeys($indicator, $version);

        if ($keys === [] || ! $this->doctors->isConfigured()) {
            return [];
        }

        $groups = null;
        $notice = null;

        try {
            // The unit's own hospital (from its HRIS mapping) is listed first.
            $companies = $unit ? $unit->hrisMappings()->distinct()->pluck('company_code')->all() : [];
            $groups = array_map(
                fn (array $doctors) => array_map(fn (array $d) => ['id' => $d['id'], 'name' => $d['name'], 'specialty' => $d['specialty']], $doctors),
                $this->doctors->byHospital($companies),
            );

            if ($groups === []) {
                $groups = null;
                $notice = 'API Dokter tidak mengirimkan dokter aktif. Ketik nama dokter.';
            }
        } catch (DokterException $exception) {
            $notice = $exception->getMessage().' Ketik nama dokter.';
        }

        return array_fill_keys($keys, ['groups' => $groups, 'notice' => $notice]);
    }

    /**
     * Replace each chosen doctor identifier with the doctor's name before the
     * form is validated. Manual entries and plain text are left as typed.
     *
     * @throws ValidationException
     */
    public function resolve(Request $request, Indicator $indicator, IndicatorFormVersion $version): void
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
        $names = [];

        foreach ($chosen as $key => $id) {
            try {
                $doctor = $this->doctors->find($id);
            } catch (DokterException $exception) {
                throw $failed($exception->getMessage().' Pilih "Lainnya — ketik manual" untuk mengetik nama dokter.');
            }

            if ($doctor === null) {
                throw $failed('Dokter yang dipilih tidak ditemukan pada daftar dokter aktif. Pilih ulang dari daftar.');
            }

            $names[$key] = $doctor['name'];
        }

        $request->merge($names);
    }

    /**
     * Doctor fields this form has: INM forms only, active text fields.
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

<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

class ObservationTest extends TestCase
{
    use CreatesInmIndicators, ReadsXlsx, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $indicators;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicators = $this->createInmIndicators();
        $this->unitA = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->unitB = Unit::create(['code' => 'ICU', 'name' => 'Intensive Care Unit', 'is_active' => true]);

        // Per-unit configuration is covered in UnitIndicatorConfigurationTest.
        $this->assignIndicators($this->unitA, $this->indicators);
        $this->assignIndicators($this->unitB, $this->indicators);
    }

    /**
     * Valid submissions for each INM form, mirroring the hospital's observation sheets.
     */
    public static function validSubmissions(): array
    {
        return [
            'identifikasi pasien' => [ObservationForms::IDENTIFICATION, [
                'officer_name' => 'Taufik Hidayat',
                'patient_name' => 'Norbayah',
                'medication_compliant' => '0',
                'procedure_compliant' => '1',
                'patient_name_verified' => '1',
                'birth_date_verified' => '1',
                'notes' => '',
            ]],
            'apd' => [ObservationForms::PPE, [
                'officer_name' => 'Nadia Contoh',
                'procedure' => 'Insersi AV shunt',
                'mask' => '1',
                'gloves' => '1',
                'gown' => '1',
                'cap' => '0',
                'closed_footwear' => '1',
                'notes' => 'Tidak memakai nursecap',
            ]],
            'pencegahan risiko jatuh' => [ObservationForms::FALL_PREVENTION, [
                'officer_name' => 'Siti Hadiyanti',
                'patient_name' => 'Sugiono',
                'medical_record_number' => '0134425',
                'initial_screening' => '1',
                'follow_up_screening' => '1',
                'education' => '0',
                'notes' => 'Memasang handrail bed',
            ]],
            'visite dokter spesialis' => [ObservationForms::SPECIALIST_VISIT, [
                'doctor_name' => 'dr. Contoh, Sp.PD',
                'patients_treated' => '5',
                'patients_visited_on_time' => '3',
                'late_visit_reason' => 'Menyelesaikan jadwal operasi',
            ]],
        ];
    }

    #[DataProvider('validSubmissions')]
    public function test_pic_unit_can_submit_each_inm_form(string $formType, array $input): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $indicator = $this->indicators[$formType];

        $response = $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...$input,
        ]);

        $response->assertRedirect(route('observations.create'));
        $response->assertSessionHasNoErrors();

        $observation = Observation::sole();
        $this->assertSame($indicator->id, $observation->indicator_id);
        $this->assertSame($this->unitA->id, $observation->unit_id);
        $this->assertSame($user->id, $observation->user_id);

        // Every submitted field is readable back with the correct type.
        foreach (ObservationForms::fields($formType) as $key => $field) {
            $stored = ObservationForms::value($observation, $key, $field);
            $expected = match ($field['type']) {
                'boolean' => (bool) $input[$key],
                'number' => (int) $input[$key],
                default => $input[$key] === '' ? null : $input[$key],
            };
            $this->assertSame($expected, $stored, "Field [{$key}] was not stored correctly.");
        }

        // The detail page shows the stored values.
        $this->actingAs($user)
            ->get(route('observations.show', $observation))
            ->assertOk()
            ->assertSee($indicator->name);
    }

    public function test_create_pages_render_each_form(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();

        $this->actingAs($user)->get(route('observations.create'))
            ->assertOk()
            ->assertSee('Form Observasi Mutu');

        foreach ($this->indicators as $formType => $indicator) {
            $response = $this->actingAs($user)->get(route('observations.create.indicator', $indicator))->assertOk();

            foreach (ObservationForms::fields($formType) as $key => $field) {
                $response->assertSee('name="'.$key.'"', false)->assertSee($field['label']);
            }
        }
    }

    public function test_user_with_observations_cannot_delete_own_account(): void
    {
        $observation = $this->observation($this->unitA, 'Pasien');

        $this->actingAs($observation->user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertModelExists($observation->user);
        $this->assertAuthenticated();
    }

    public function test_fall_prevention_keeps_leading_zeros_in_medical_record_number(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        [, $input] = self::validSubmissions()['pencegahan risiko jatuh'];

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::FALL_PREVENTION]->id,
            'observation_date' => now()->toDateString(),
            ...$input,
        ]);

        $this->assertSame('0134425', Observation::sole()->data['medical_record_number']);
    }

    public function test_required_fields_are_validated_with_indonesian_messages(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();

        $response = $this->actingAs($user)
            ->from(route('observations.create.indicator', $this->indicators[ObservationForms::PPE]))
            ->post(route('observations.store'), [
                'indicator_id' => $this->indicators[ObservationForms::PPE]->id,
                'observation_date' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors(['officer_name', 'procedure', 'mask', 'gloves', 'gown', 'cap', 'closed_footwear']);
        $this->assertSame('Nama Petugas wajib diisi.', session('errors')->first('officer_name'));
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_visited_patients_cannot_exceed_treated_patients(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();

        $response = $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::SPECIALIST_VISIT]->id,
            'observation_date' => now()->toDateString(),
            'doctor_name' => 'dr. Contoh',
            'patients_treated' => '2',
            'patients_visited_on_time' => '3',
        ]);

        $response->assertSessionHasErrors('patients_visited_on_time');
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_future_observation_date_is_rejected(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        [, $input] = self::validSubmissions()['identifikasi pasien'];

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'observation_date' => now()->addDay()->toDateString(),
            ...$input,
        ])->assertSessionHasErrors('observation_date');
    }

    public function test_submitted_unit_id_is_ignored(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        [, $input] = self::validSubmissions()['identifikasi pasien'];

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'observation_date' => now()->toDateString(),
            'unit_id' => $this->unitB->id,
            'user_id' => 999,
            ...$input,
        ]);

        $observation = Observation::sole();
        $this->assertSame($this->unitA->id, $observation->unit_id);
        $this->assertSame($user->id, $observation->user_id);
    }

    public function test_inactive_or_non_inm_indicator_is_rejected(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $indicator = $this->indicators[ObservationForms::IDENTIFICATION];
        $indicator->update(['is_active' => false]);
        [, $input] = self::validSubmissions()['identifikasi pasien'];

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...$input,
        ])->assertSessionHasErrors('indicator_id');

        $this->actingAs($user)
            ->get(route('observations.create.indicator', $indicator))
            ->assertNotFound();
    }

    public function test_pic_mutu_cannot_create_observations(): void
    {
        $user = User::factory()->picMutu()->create();

        $this->actingAs($user)->get(route('observations.create'))->assertForbidden();
        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
        ])->assertForbidden();
    }

    public function test_pic_unit_without_active_unit_cannot_create(): void
    {
        $withoutUnit = User::factory()->picUnit()->create();
        $this->actingAs($withoutUnit)->get(route('observations.create'))->assertForbidden();

        $this->unitA->update(['is_active' => false]);
        $inactiveUnit = User::factory()->picUnit($this->unitA)->create();
        $this->actingAs($inactiveUnit)->get(route('observations.create'))->assertForbidden();
    }

    public function test_pic_unit_only_sees_own_unit_history(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $own = $this->observation($this->unitA, 'Pasien Unit A');
        $other = $this->observation($this->unitB, 'Pasien Unit B');

        $report = ['indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()];

        $this->actingAs($user)
            ->get(route('observations.index', $report))
            ->assertOk()
            ->assertSee('Pasien Unit A')
            ->assertDontSee('Pasien Unit B');

        // Asking for another unit is refused, not answered.
        $this->actingAs($user)
            ->get(route('observations.index', [...$report, 'unit_id' => $this->unitB->id]))
            ->assertSessionHasErrors(['unit_id' => 'Anda hanya dapat melihat laporan unit Anda sendiri.']);

        $this->actingAs($user)->get(route('observations.show', $own))->assertOk();
        $this->actingAs($user)->get(route('observations.show', $other))->assertForbidden();
    }

    public function test_pic_unit_without_unit_sees_no_history(): void
    {
        $user = User::factory()->picUnit()->create();
        $this->observation($this->unitA, 'Pasien Unit A');

        $this->actingAs($user)
            ->get(route('observations.index'))
            ->assertOk()
            ->assertSee('belum terhubung dengan unit')
            ->assertViewHas('indicators', fn ($indicators) => $indicators->isEmpty())
            ->assertDontSee('Pasien Unit A');

        // No unit, no indicators to report on.
        $this->actingAs($user)
            ->get(route('observations.index', ['indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id]))
            ->assertSessionHasErrors('indicator_id');
    }

    public function test_pic_mutu_sees_all_units_and_can_filter(): void
    {
        $user = User::factory()->picMutu()->create();
        $this->observation($this->unitA, 'Pasien Unit A', '2026-09-01');
        $this->observation($this->unitB, 'Pasien Unit B', '2026-10-01');

        $report = ['indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id, 'start_date' => '2026-09-01', 'end_date' => '2026-10-31'];

        $this->actingAs($user)->get(route('observations.index', [...$report, 'unit_id' => 'semua']))
            ->assertSee('Pasien Unit A')
            ->assertSee('Pasien Unit B');

        $this->actingAs($user)->get(route('observations.index', [...$report, 'unit_id' => $this->unitB->id]))
            ->assertDontSee('Pasien Unit A')
            ->assertSee('Pasien Unit B');

        $this->actingAs($user)->get(route('observations.index', [...$report, 'start_date' => '2026-09-15']))
            ->assertDontSee('Pasien Unit A')
            ->assertSee('Pasien Unit B');
    }

    public function test_legacy_identification_record_without_data_is_readable(): void
    {
        $user = User::factory()->admin()->create(['unit_id' => $this->unitA->id]);
        $legacy = Observation::create([
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $this->unitA->id,
            'user_id' => $user->id,
            'observation_date' => '2026-10-29',
            'officer_name' => 'Petugas Lama',
            'patient_name' => 'Pasien Lama',
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => false,
            'notes' => null,
        ]);
        $this->assertNull($legacy->fresh()->data);

        $this->actingAs($user)->get(route('observations.show', $legacy))
            ->assertOk()
            ->assertSee('Petugas Lama')
            ->assertSee('Pasien Lama')
            ->assertSee('Tidak');
    }

    public function test_export_is_limited_to_admin_and_pic_mutu(): void
    {
        $picUnit = User::factory()->picUnit($this->unitA)->create();
        $this->actingAs($picUnit)->get(route('observations.export', 'excel'))->assertForbidden();
        $this->actingAs($picUnit)->get(route('observations.export', 'pdf'))->assertForbidden();
    }

    public function test_excel_export_contains_filtered_rows(): void
    {
        $user = User::factory()->picMutu()->create();
        $this->observation($this->unitA, 'Pasien Unit A');
        $this->observation($this->unitB, '=HYPERLINK("x")');

        $response = $this->actingAs($user)->get(route('observations.export', [
            'format' => 'excel',
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $sheet = $this->xlsxSheets($response->streamedContent())['Kepatuhan Identifikasi Pasien'];
        $this->assertContains('Nama Pasien', $this->xlsxValues($sheet[5]));
        $patients = array_map(fn (array $row) => $row['F']['value'], array_slice($sheet, 5, null, true));
        $this->assertSame(['Pasien Unit A', '=HYPERLINK("x")'], array_values($patients));
        // User text is stored as text: a spreadsheet never evaluates it as a formula.
        $this->assertSame('text', $sheet[7]['F']['type']);

        $filtered = $this->xlsxSheets($this->actingAs($user)->get(route('observations.export', [
            'format' => 'excel',
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'unit_id' => $this->unitA->id,
        ]))->streamedContent());
        $this->assertStringNotContainsString('HYPERLINK', json_encode($filtered));
        $this->assertStringContainsString('Pasien Unit A', json_encode($filtered));
    }

    public function test_pdf_export_is_generated(): void
    {
        $user = User::factory()->admin()->create();
        $this->observation($this->unitA, 'Pasien Unit A');

        $response = $this->actingAs($user)->get(route('observations.export', [
            'format' => 'pdf',
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_dashboard_counts_are_scoped_to_role(): void
    {
        $this->observation($this->unitA, 'A1', now()->toDateString());
        $this->observation($this->unitA, 'A2', now()->toDateString());
        $this->observation($this->unitB, 'B1', now()->toDateString());

        $picUnit = User::factory()->picUnit($this->unitA)->create();
        $this->actingAs($picUnit)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('total', 2);

        $picMutu = User::factory()->picMutu()->create();
        $this->actingAs($picMutu)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('total', 3)
            ->assertDontSee('%</p>', false);

        $this->actingAs($picMutu)->get(route('dashboard', ['unit_id' => $this->unitB->id]))
            ->assertViewHas('total', 1);
    }

    private function observation(Unit $unit, string $patientName, ?string $date = null): Observation
    {
        $author = User::factory()->picUnit($unit)->create();

        return Observation::create([
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $unit->id,
            'user_id' => $author->id,
            'observation_date' => $date ?? now()->toDateString(),
            'officer_name' => 'Petugas',
            'patient_name' => $patientName,
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);
    }
}

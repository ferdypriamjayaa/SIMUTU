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
use Tests\TestCase;

class ObservationEditTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

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

        $this->assignIndicators($this->unitA, $this->indicators);
        $this->assignIndicators($this->unitB, $this->indicators);
    }

    /**
     * Original submission and its correction for each INM form.
     */
    public static function edits(): array
    {
        $original = ObservationTest::validSubmissions();

        return [
            'identifikasi pasien' => [...$original['identifikasi pasien'], [
                'officer_name' => 'Taufik H.',
                'patient_name' => 'Norbayah Binti A',
                'medication_compliant' => '1',
                'procedure_compliant' => '0',
                'patient_name_verified' => '1',
                'birth_date_verified' => '0',
                'notes' => 'Tanggal lahir tidak dikonfirmasi',
            ]],
            'apd' => [...$original['apd'], [
                'officer_name' => 'Nadia Contoh',
                'procedure' => 'Pemasangan infus',
                'mask' => '0',
                'gloves' => '1',
                'gown' => '0',
                'cap' => '1',
                'closed_footwear' => '1',
                'notes' => '',
            ]],
            'pencegahan risiko jatuh' => [...$original['pencegahan risiko jatuh'], [
                'officer_name' => 'Siti Hadiyanti',
                'patient_name' => 'Sugiono',
                'medical_record_number' => '0099887',
                'initial_screening' => '0',
                'follow_up_screening' => '1',
                'education' => '1',
                'notes' => 'Edukasi keluarga',
            ]],
            'visite dokter spesialis' => [...$original['visite dokter spesialis'], [
                'doctor_name' => 'dr. Contoh, Sp.PD',
                'patients_treated' => '8',
                'patients_visited_on_time' => '8',
                'late_visit_reason' => '',
            ]],
        ];
    }

    #[DataProvider('edits')]
    public function test_pic_unit_can_edit_each_inm_form(string $formType, array $original, array $changed): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $observation = $this->submit($user, $formType, $original);

        $page = $this->actingAs($user)->get(route('observations.edit', $observation))->assertOk();

        // The edit page is prefilled with the stored values.
        foreach (ObservationForms::fields($formType) as $key => $field) {
            $value = $original[$key];
            if ($field['type'] === 'boolean') {
                $this->assertMatchesRegularExpression('/name="'.$key.'" value="'.$value.'"\s+checked/', $page->getContent());
            } elseif ($value !== '') {
                $page->assertSee(e($value), false);
            }
        }

        $date = now()->subDay()->toDateString();

        $this->actingAs($user)
            ->put(route('observations.update', $observation), ['observation_date' => $date, ...$changed])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('observations.show', $observation));

        $observation->refresh();
        $this->assertSame($date, $observation->observation_date->toDateString());
        $this->assertSame($this->indicators[$formType]->id, $observation->indicator_id);
        $this->assertSame($this->unitA->id, $observation->unit_id);
        $this->assertSame($user->id, $observation->user_id);

        foreach (ObservationForms::fields($formType) as $key => $field) {
            $expected = match ($field['type']) {
                'boolean' => (bool) $changed[$key],
                'number' => (int) $changed[$key],
                default => $changed[$key] === '' ? null : $changed[$key],
            };
            $this->assertSame($expected, ObservationForms::value($observation, $key, $field), "Field [{$key}] was not updated.");
        }
    }

    public function test_boolean_fields_are_prefilled_as_checked(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $observation = $this->submit($user, ObservationForms::PPE, ObservationTest::validSubmissions()['apd'][1]);

        $html = $this->actingAs($user)->get(route('observations.edit', $observation))->getContent();

        // cap = 0, mask = 1 in the original submission.
        $this->assertMatchesRegularExpression('/name="cap" value="0"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="mask" value="1"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="cap" value="1"\s+checked/', $html);
    }

    public function test_update_keeps_data_keys_outside_the_form(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $input = ObservationTest::validSubmissions()['apd'][1];
        $observation = $this->submit($user, ObservationForms::PPE, $input);
        $observation->update(['data' => [...$observation->data, 'legacy_field' => 'arsip']]);

        $this->actingAs($user)
            ->put(route('observations.update', $observation), [
                'observation_date' => now()->toDateString(),
                ...$input,
                'procedure' => 'Tindakan baru',
            ])
            ->assertSessionHasNoErrors();

        $data = $observation->fresh()->data;
        $this->assertSame('arsip', $data['legacy_field']);
        $this->assertSame('Tindakan baru', $data['procedure']);
    }

    public function test_update_is_validated(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $input = ObservationTest::validSubmissions()['visite dokter spesialis'][1];
        $observation = $this->submit($user, ObservationForms::SPECIALIST_VISIT, $input);

        $this->actingAs($user)
            ->put(route('observations.update', $observation), [
                ...$input,
                'observation_date' => now()->addDay()->toDateString(),
                'doctor_name' => '',
                'patients_visited_on_time' => '99',
            ])
            ->assertSessionHasErrors(['observation_date', 'doctor_name', 'patients_visited_on_time']);

        $this->assertSame($input['doctor_name'], $observation->fresh()->data['doctor_name']);
    }

    public function test_submitted_indicator_unit_and_author_are_ignored(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $input = ObservationTest::validSubmissions()['identifikasi pasien'][1];
        $observation = $this->submit($user, ObservationForms::IDENTIFICATION, $input);

        $this->actingAs($user)->put(route('observations.update', $observation), [
            'observation_date' => now()->toDateString(),
            ...$input,
            'indicator_id' => $this->indicators[ObservationForms::PPE]->id,
            'unit_id' => $this->unitB->id,
            'user_id' => User::factory()->admin()->create()->id,
        ])->assertSessionHasNoErrors();

        $observation->refresh();
        $this->assertSame($this->indicators[ObservationForms::IDENTIFICATION]->id, $observation->indicator_id);
        $this->assertSame($this->unitA->id, $observation->unit_id);
        $this->assertSame($user->id, $observation->user_id);
    }

    public function test_pic_unit_can_edit_colleagues_observation_in_same_unit(): void
    {
        $observation = $this->identification($this->unitA);
        $colleague = User::factory()->picUnit($this->unitA)->create();

        $this->actingAs($colleague)->get(route('observations.edit', $observation))->assertOk();
    }

    public function test_pic_unit_cannot_edit_other_units_observation(): void
    {
        $observation = $this->identification($this->unitB);
        $user = User::factory()->picUnit($this->unitA)->create();

        $this->actingAs($user)->get(route('observations.edit', $observation))->assertForbidden();
        $this->actingAs($user)
            ->put(route('observations.update', $observation), $this->identificationInput(['patient_name' => 'Diubah']))
            ->assertForbidden();

        $this->assertSame('Pasien', $observation->fresh()->patient_name);
    }

    public function test_pic_unit_cannot_edit_unassigned_or_inactive_indicator(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $observation = $this->identification($this->unitA);
        $indicator = $this->indicators[ObservationForms::IDENTIFICATION];

        $this->unitA->assignedIndicators()->detach($indicator->id);
        $this->actingAs($user)->get(route('observations.edit', $observation))->assertForbidden();

        $this->assignIndicators($this->unitA, $this->indicators);
        $indicator->update(['is_active' => false]);
        $this->actingAs($user)->get(route('observations.edit', $observation))->assertForbidden();
        $this->actingAs($user)
            ->put(route('observations.update', $observation), $this->identificationInput(['patient_name' => 'Diubah']))
            ->assertForbidden();

        // History stays readable.
        $this->actingAs($user)->get(route('observations.show', $observation))->assertOk();
    }

    public function test_pic_unit_with_inactive_unit_cannot_edit(): void
    {
        $user = User::factory()->picUnit($this->unitA)->create();
        $observation = $this->identification($this->unitA);
        $this->unitA->update(['is_active' => false]);

        $this->actingAs($user)->get(route('observations.edit', $observation))->assertForbidden();
    }

    public function test_pic_mutu_cannot_edit(): void
    {
        $observation = $this->identification($this->unitA);
        $picMutu = User::factory()->picMutu()->create();

        $this->actingAs($picMutu)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertDontSee(route('observations.edit', $observation));
        $this->actingAs($picMutu)->get(route('observations.edit', $observation))->assertForbidden();
        $this->actingAs($picMutu)
            ->put(route('observations.update', $observation), $this->identificationInput(['patient_name' => 'Diubah']))
            ->assertForbidden();
    }

    public function test_admin_can_edit_any_unit_observation(): void
    {
        $observation = $this->identification($this->unitB);
        $admin = User::factory()->admin()->create();
        $this->indicators[ObservationForms::IDENTIFICATION]->update(['is_active' => false]);

        $this->actingAs($admin)->get(route('observations.show', $observation))
            ->assertSee(route('observations.edit', $observation));

        $this->actingAs($admin)
            ->put(route('observations.update', $observation), $this->identificationInput(['patient_name' => 'Diubah Admin']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Diubah Admin', $observation->fresh()->patient_name);
    }

    public function test_history_shows_edit_link_only_for_editable_rows(): void
    {
        $own = $this->identification($this->unitA);
        $user = User::factory()->picUnit($this->unitA)->create();

        $report = route('observations.index', ['indicator_id' => $own->indicator_id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]);

        $this->actingAs($user)->get($report)
            ->assertSee(route('observations.edit', $own));

        $this->actingAs(User::factory()->picMutu()->create())->get($report)
            ->assertDontSee(route('observations.edit', $own));
    }

    public function test_pic_unit_can_delete_own_unit_observation(): void
    {
        $observation = $this->identification($this->unitA);
        $user = User::factory()->picUnit($this->unitA)->create();

        $this->actingAs($user)->get(route('observations.show', $observation))
            ->assertSee('action="'.route('observations.destroy', $observation).'"', false);

        $this->actingAs($user)->delete(route('observations.destroy', $observation))
            ->assertRedirect(route('observations.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($observation);
    }

    public function test_admin_can_delete_any_unit_observation(): void
    {
        $observation = $this->identification($this->unitB);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('observations.destroy', $observation))
            ->assertRedirect(route('observations.index'));

        $this->assertModelMissing($observation);
    }

    public function test_delete_is_denied_outside_access_rules(): void
    {
        $observation = $this->identification($this->unitB);
        $otherUnit = User::factory()->picUnit($this->unitA)->create();
        $picMutu = User::factory()->picMutu()->create();
        $sameUnit = User::factory()->picUnit($this->unitB)->create();

        $this->actingAs($otherUnit)->delete(route('observations.destroy', $observation))->assertForbidden();
        $this->actingAs($picMutu)->delete(route('observations.destroy', $observation))->assertForbidden();
        $this->actingAs($picMutu)->get(route('observations.show', $observation))
            ->assertDontSee('action="'.route('observations.destroy', $observation).'"', false);

        $this->indicators[ObservationForms::IDENTIFICATION]->update(['is_active' => false]);
        $this->actingAs($sameUnit)->delete(route('observations.destroy', $observation))->assertForbidden();

        $this->assertModelExists($observation);
    }

    private function submit(User $user, string $formType, array $input): Observation
    {
        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[$formType]->id,
            'observation_date' => now()->toDateString(),
            ...$input,
        ])->assertSessionHasNoErrors();

        return Observation::latest('id')->firstOrFail();
    }

    private function identification(Unit $unit): Observation
    {
        return Observation::create([
            'indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $unit->id,
            'user_id' => User::factory()->picUnit($unit)->create()->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas',
            'patient_name' => 'Pasien',
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);
    }

    private function identificationInput(array $overrides = []): array
    {
        return [
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
            ...$overrides,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\ObservationValue;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use App\Support\Xlsx\XlsxWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Observations entered on forms built in the form builder, and how they
 * relate to form versions.
 */
class DynamicObservationTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, ReadsXlsx, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $indicators;

    private User $admin;

    private User $picUnit;

    private Unit $unit;

    private Indicator $indicator;

    /** A new version of the PPE form with one field of each remaining type. */
    private IndicatorFormVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicators = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->assignIndicators($this->unit, $this->indicators);
        $this->picUnit = User::factory()->picUnit($this->unit)->create();
        $this->indicator = $this->indicators[ObservationForms::PPE];

        $this->version = $this->publishNewVersion($this->admin, $this->indicator, [
            ['label' => 'Tanggal Tindakan', 'type' => 'date', 'key' => 'procedure_date', 'is_required' => false],
            ['label' => 'Shift', 'type' => 'select', 'key' => 'shift', 'options' => [['label' => 'Pagi', 'value' => 'pagi'], ['label' => 'Malam', 'value' => 'malam']]],
            ['label' => 'Profesi', 'type' => 'radio', 'key' => 'profession', 'options' => [['label' => 'Perawat', 'value' => 'nurse'], ['label' => 'Dokter', 'value' => 'doctor']]],
            ['label' => 'APD Tambahan', 'type' => 'checkbox', 'key' => 'extra_ppe', 'is_required' => false, 'options' => [
                ['label' => 'Face Shield', 'value' => 'face_shield'],
                ['label' => 'Kacamata Google', 'value' => 'goggles'],
                ['label' => 'Sepatu Boot', 'value' => 'boots'],
            ]],
            ['label' => 'Durasi (menit)', 'type' => 'number', 'key' => 'duration', 'min_value' => 1, 'max_value' => 600, 'is_required' => false],
            ['label' => 'Kode Ruang', 'type' => 'text', 'key' => 'room_code', 'format' => 'code', 'min_length' => 2, 'max_length' => 6, 'is_required' => false],
        ]);
    }

    private function input(array $overrides = []): array
    {
        return [
            'indicator_id' => $this->indicator->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['apd'][1],
            'procedure_date' => now()->subDay()->toDateString(),
            'shift' => 'malam',
            'profession' => 'nurse',
            'extra_ppe' => ['face_shield', 'boots'],
            'duration' => '45',
            'room_code' => 'IGD-2',
            ...$overrides,
        ];
    }

    private function submit(array $overrides = []): Observation
    {
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->input($overrides))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('observations.create'));

        return Observation::latest('id')->firstOrFail();
    }

    public function test_form_renders_every_field_type_from_the_published_version(): void
    {
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->indicator))
            ->assertOk()
            ->assertSee('name="form_version_id" value="'.$this->version->id.'"', false)
            ->assertSee('type="date" name="procedure_date"', false)
            ->assertSee('<select name="shift"', false)
            ->assertSee('type="radio" name="profession" value="doctor"', false)
            ->assertSee('type="checkbox" name="extra_ppe[]" value="goggles"', false)
            ->assertSee('type="number" name="duration"', false)
            ->assertSee('Kacamata Google')
            ->assertSee('Durasi (menit)');
    }

    public function test_pic_unit_can_submit_valid_observations(): void
    {
        $observation = $this->submit();

        $this->assertSame($this->version->id, $observation->indicator_form_version_id);
        $this->assertSame($this->unit->id, $observation->unit_id);
        $this->assertSame($this->picUnit->id, $observation->user_id);

        $stored = $observation->values()->with('field')->get()->groupBy('field.key')->map->pluck('value')->map->all();

        $this->assertSame(['Nadia Contoh'], $stored['officer_name']);
        $this->assertSame(['1'], $stored['mask']);
        $this->assertSame(['0'], $stored['cap']);
        $this->assertSame([now()->subDay()->toDateString()], $stored['procedure_date']);
        $this->assertSame(['malam'], $stored['shift']);
        $this->assertSame(['nurse'], $stored['profession']);
        $this->assertSame(['45'], $stored['duration']);
        $this->assertSame(['IGD-2'], $stored['room_code']);

        // Choice answers point at the option row of this exact version.
        $shift = $observation->values()->whereHas('field', fn ($q) => $q->where('key', 'shift'))->with('option')->sole();
        $this->assertSame('Malam', $shift->option->label);
        $this->assertSame($this->version->id, $shift->field->indicator_form_version_id);

        $this->actingAs($this->picUnit)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertSee('Malam')
            ->assertSee('Perawat')
            ->assertSee('Face Shield, Sepatu Boot')
            ->assertSee(now()->subDay()->format('d-m-Y'))
            ->assertSee('Formulir versi 2');

        $this->actingAs($this->picUnit)->get(route('observations.index', ['indicator_id' => $this->indicator->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Nadia Contoh');
    }

    public function test_checkbox_values_are_saved_correctly(): void
    {
        $observation = $this->submit();
        $field = $this->version->fields()->where('key', 'extra_ppe')->with('options')->sole();

        $rows = ObservationValue::where('observation_id', $observation->id)->where('indicator_field_id', $field->id)->get();

        $this->assertEqualsCanonicalizing(['face_shield', 'boots'], $rows->pluck('value')->all());
        $this->assertEqualsCanonicalizing(
            $field->options->whereIn('value', ['face_shield', 'boots'])->pluck('id')->all(),
            $rows->pluck('indicator_field_option_id')->all(),
        );

        // Editing replaces the selection; clearing it removes every row.
        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), $this->input(['extra_ppe' => ['goggles']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['goggles'], ObservationValue::where('indicator_field_id', $field->id)->pluck('value')->all());

        $cleared = $this->input();
        unset($cleared['extra_ppe']);
        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), $cleared)->assertSessionHasNoErrors();
        $this->assertSame(0, ObservationValue::where('indicator_field_id', $field->id)->count());

        // The edit page ticks the stored options.
        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), $this->input())->assertSessionHasNoErrors();
        $html = $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="extra_ppe\[\]" value="boots"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="extra_ppe\[\]" value="goggles"\s+checked/', $html);
    }

    public function test_invalid_required_fields_are_rejected(): void
    {
        $input = $this->input();
        unset($input['shift'], $input['profession'], $input['officer_name']);

        $this->actingAs($this->picUnit)
            ->from(route('observations.create.indicator', $this->indicator))
            ->post(route('observations.store'), $input)
            ->assertSessionHasErrors(['shift', 'profession', 'officer_name']);

        $this->assertSame('Shift wajib diisi.', session('errors')->first('shift'));
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('observation_values', 0);
    }

    public function test_every_field_type_is_validated_on_the_server(): void
    {
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->input([
            'procedure_date' => '31-12-2026',
            'shift' => 'sore',
            'profession' => ['nurse'],
            'extra_ppe' => ['face_shield', 'helm'],
            'duration' => '601',
            'room_code' => 'ruang igd',
            'mask' => 'ya',
        ]))->assertSessionHasErrors(['procedure_date', 'shift', 'profession', 'extra_ppe.1', 'duration', 'room_code', 'mask']);

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->input([
            'extra_ppe' => 'face_shield',
            'duration' => '4.5',
            'room_code' => 'A',
        ]))->assertSessionHasErrors(['extra_ppe', 'duration', 'room_code']);

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_fields_outside_the_form_and_inactive_fields_are_ignored(): void
    {
        $third = $this->publishNewVersion($this->admin, $this->indicator, [], function (array $fields) {
            foreach ($fields as &$field) {
                if ($field['key'] === 'shift') {
                    $field['is_active'] = false;
                }
            }

            return $fields;
        });

        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->indicator))
            ->assertOk()
            ->assertDontSee('name="shift"', false);

        // No "shift" needed any more, and a made-up key is not stored anywhere.
        $input = $this->input(['unknown_field' => 'x', 'shift' => 'malam']);
        $this->actingAs($this->picUnit)->post(route('observations.store'), $input)->assertSessionHasNoErrors();

        $observation = Observation::sole();
        $this->assertSame($third->id, $observation->indicator_form_version_id);
        $this->assertNotContains('shift', $observation->values()->with('field')->get()->pluck('field.key')->all());
        $this->assertArrayNotHasKey('unknown_field', $observation->data ?? []);
    }

    public function test_default_values_prefill_a_new_form(): void
    {
        $this->publishNewVersion($this->admin, $this->indicator, [], function (array $fields) {
            foreach ($fields as &$field) {
                $field['default_value'] = match ($field['key']) {
                    'shift' => 'pagi',
                    'mask' => '1',
                    'duration' => '30',
                    default => $field['default_value'] ?? null,
                };
            }

            return $fields;
        });

        $html = $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->indicator))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="pagi"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/name="mask" value="1"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="duration"[^>]*value="30"/', $html);
    }

    public function test_observations_retain_their_original_form_version(): void
    {
        $observation = $this->submit();

        // The form changes: a label is renamed, an option relabelled, a field added.
        $third = $this->publishNewVersion($this->admin, $this->indicator, [['label' => 'Supervisor', 'type' => 'text', 'key' => 'supervisor']], function (array $fields) {
            foreach ($fields as &$field) {
                if ($field['key'] === 'shift') {
                    $field['label'] = 'Waktu Jaga';
                    $field['options'][1]['label'] = 'Shift Malam';
                }
            }

            return $fields;
        });

        $observation->refresh();
        $this->assertSame($this->version->id, $observation->indicator_form_version_id);
        $this->assertSame(IndicatorFormVersion::STATUS_ARCHIVED, $this->version->fresh()->status);
        $this->assertSame(
            [$this->version->id],
            $observation->values()->with('field')->get()->pluck('field.indicator_form_version_id')->unique()->all(),
        );

        // It still reads with the labels of the form it was entered on.
        $this->actingAs($this->picUnit)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertSee('Formulir versi 2')
            ->assertSee('Shift')
            ->assertSee('Malam')
            ->assertDontSee('Waktu Jaga')
            ->assertDontSee('Shift Malam')
            ->assertDontSee('Supervisor');

        // ...and is edited on that same form, not the new one.
        $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))
            ->assertOk()
            ->assertDontSee('name="supervisor"', false);

        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), $this->input(['shift' => 'pagi']))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->version->id, $observation->fresh()->indicator_form_version_id);

        // New observations use the new version.
        $new = $this->submit(['supervisor' => 'Kepala Ruang']);
        $this->assertSame($third->id, $new->indicator_form_version_id);
    }

    public function test_historical_observations_remain_readable_after_a_new_version_is_published(): void
    {
        $picMutu = User::factory()->picMutu()->create();

        // One record from before form versions, one on version 2.
        $legacy = Observation::create([
            'indicator_id' => $this->indicator->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->picUnit->id,
            'observation_date' => now()->subDays(2)->toDateString(),
            'officer_name' => 'Petugas Lama',
            'notes' => 'Catatan lama',
            'data' => ['procedure' => 'Tindakan lama', 'mask' => true, 'gloves' => false, 'gown' => true, 'cap' => true, 'closed_footwear' => true],
        ]);
        $versioned = $this->submit();

        $this->publishNewVersion($this->admin, $this->indicator, [['label' => 'Supervisor', 'type' => 'text', 'key' => 'supervisor']]);
        $latest = $this->submit(['supervisor' => 'Kepala Ruang']);

        $this->actingAs($picMutu)->get(route('observations.show', $legacy))
            ->assertOk()->assertSee('Petugas Lama')->assertSee('Tindakan lama')->assertSee('Formulir versi 1');
        $this->actingAs($picMutu)->get(route('observations.show', $versioned))
            ->assertOk()->assertSee('Face Shield, Sepatu Boot')->assertSee('Formulir versi 2');

        $this->actingAs($picMutu)->get(route('observations.index', ['indicator_id' => $this->indicator->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertOk()->assertSee('Petugas Lama')->assertSee('Nadia Contoh');

        // One export holds all three, with a column per field across versions.
        $sheets = $this->xlsxSheets($this->actingAs($picMutu)
            ->get(route('observations.export', ['format' => 'excel', 'indicator_id' => $this->indicator->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertOk()->streamedContent());
        $this->assertSame(['Ringkasan', XlsxWorkbook::sheetName($this->indicator->name)], array_keys($sheets));
        $sheet = $sheets[XlsxWorkbook::sheetName($this->indicator->name)];
        $headings = $this->xlsxValues($sheet[5]);
        $rows = array_values(array_filter($sheet, fn (int $row) => $row > 5, ARRAY_FILTER_USE_KEY));

        $this->assertCount(3, $rows);
        $this->assertContains('Supervisor', $headings);
        $this->assertContains('APD Tambahan', $headings);
        $cell = fn (int $row, string $heading) => $this->xlsxValues($rows[$row])[array_search($heading, $headings, true)];
        $this->assertSame('Petugas Lama', $cell(0, 'Nama Petugas'));
        $this->assertContains('Tindakan lama', $this->xlsxValues($rows[0]));
        $this->assertSame('Face Shield, Sepatu Boot', $cell(1, 'APD Tambahan'));
        $this->assertSame('Kepala Ruang', $cell(2, 'Supervisor'));
        $this->assertNull($cell(0, 'Supervisor'));
        // The report carries the answers only, not the form version they were recorded on.
        $this->assertNotContains('Versi Formulir', $headings);
        $this->assertCount(count($headings), $rows[0]);

        $this->actingAs($picMutu)->get(route('observations.export', ['format' => 'pdf', 'indicator_id' => $this->indicator->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))->assertOk();
        $this->assertSame(3, Observation::count());
        $this->assertNull($legacy->fresh()->indicator_form_version_id);
        $this->assertNotNull($latest->indicator_form_version_id);
    }

    public function test_record_from_before_form_versions_is_edited_on_the_first_form(): void
    {
        $indicator = $this->indicators[ObservationForms::IDENTIFICATION];
        $legacy = Observation::create([
            'indicator_id' => $indicator->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->picUnit->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas Lama',
            'patient_name' => 'Pasien Lama',
            'medication_compliant' => true,
            'procedure_compliant' => false,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);

        $html = $this->actingAs($this->picUnit)->get(route('observations.edit', $legacy))->assertOk()->assertSee('Petugas Lama')->getContent();
        $this->assertMatchesRegularExpression('/name="procedure_compliant" value="0"\s+checked/', $html);

        $this->actingAs($this->picUnit)->put(route('observations.update', $legacy), [
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
        ])->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertSame($indicator->baselineFormVersion->id, $legacy->indicator_form_version_id);
        $this->assertSame('Taufik Hidayat', $legacy->officer_name);
        $this->assertSame(6, $legacy->values()->count());
    }

    public function test_submission_on_an_outdated_form_is_sent_back_to_the_current_one(): void
    {
        $stale = $this->input(['form_version_id' => $this->indicator->baselineFormVersion->id]);

        $this->actingAs($this->picUnit)->post(route('observations.store'), $stale)
            ->assertRedirect(route('observations.create.indicator', $this->indicator))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_indicator_without_a_published_form_cannot_be_recorded(): void
    {
        $indicator = $this->indicators[ObservationForms::FALL_PREVENTION];
        $indicator->publishedFormVersion->update(['status' => IndicatorFormVersion::STATUS_ARCHIVED]);

        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $indicator))->assertNotFound();
        $this->actingAs($this->picUnit)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['pencegahan risiko jatuh'][1],
        ])->assertSessionHasErrors('indicator_id');
    }

    public function test_pic_unit_cannot_submit_or_read_for_another_unit(): void
    {
        $otherUnit = Unit::create(['code' => 'ICU', 'name' => 'Intensive Care Unit', 'is_active' => true]);
        $outsider = User::factory()->picUnit($otherUnit)->create();

        // The other unit is not assigned this indicator.
        $this->actingAs($outsider)->get(route('observations.create.indicator', $this->indicator))->assertForbidden();
        $this->actingAs($outsider)->post(route('observations.store'), $this->input(['unit_id' => $this->unit->id]))
            ->assertSessionHasErrors('indicator_id');
        $this->assertDatabaseCount('observations', 0);

        $observation = $this->submit(['unit_id' => $otherUnit->id]);
        $this->assertSame($this->unit->id, $observation->unit_id);

        $this->actingAs($outsider)->get(route('observations.show', $observation))->assertForbidden();
        $this->actingAs($outsider)->get(route('observations.edit', $observation))->assertForbidden();
        $this->actingAs($outsider)->put(route('observations.update', $observation), $this->input())->assertForbidden();
        $this->actingAs($outsider)->delete(route('observations.destroy', $observation))->assertForbidden();
    }

    public function test_deleting_an_observation_removes_its_answers_only(): void
    {
        $observation = $this->submit();
        $fieldCount = $this->version->fields()->count();

        $this->actingAs($this->picUnit)->delete(route('observations.destroy', $observation))
            ->assertRedirect(route('observations.index'));

        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('observation_values', 0);
        $this->assertSame($fieldCount, $this->version->fields()->count());
    }
}

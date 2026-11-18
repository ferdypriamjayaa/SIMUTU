<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Observation;
use App\Models\ObservationValue;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * PIC Unit records the IMP RS indicators mapped to their unit
 * (indicator_unit) through the same observation workflow as INM, once the
 * indicator's form is published. IMP Unit is not an input category.
 */
class ImpRsObservationInputTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $inm;

    private User $admin;

    private User $picUnit;

    private Unit $ns3b;

    private Unit $other;

    /** IMP RS, mapped to NS3B, published form. */
    private Indicator $ssc;

    /** IMP RS, mapped to NS3B, draft only. */
    private Indicator $draftOnly;

    /** IMP RS, mapped to another unit only, published form. */
    private Indicator $elsewhere;

    /** IMP Unit, mapped to NS3B, published form. */
    private Indicator $impUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inm = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->ns3b = Unit::create(['code' => 'NS3B', 'name' => 'Perawat Lantai 3 Gedung B', 'is_active' => true]);
        $this->other = Unit::create(['code' => 'NS2A', 'name' => 'Perawat Lantai 2 Gedung A', 'is_active' => true]);
        $this->assignIndicators($this->ns3b, $this->inm, [ObservationForms::IDENTIFICATION, ObservationForms::PPE]);
        $this->picUnit = User::factory()->picUnit($this->ns3b)->create();

        $rs = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'Indikator Mutu Pelayanan Rumah Sakit']);
        $unit = IndicatorCategory::forceCreate(['code' => 'IMP_UNIT', 'slug' => 'imp-unit', 'name' => 'Indikator Mutu Pelayanan Unit']);

        $this->ssc = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kepatuhan Surgical Safety Checklist', 'is_active' => true]);
        $this->draftOnly = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kepatuhan Irigasi Post TURP', 'is_active' => true]);
        $this->elsewhere = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kepatuhan Hand Hygiene Rawat Jalan', 'is_active' => true]);
        $this->impUnit = Indicator::create(['indicator_category_id' => $unit->id, 'unit_id' => $this->ns3b->id, 'name' => 'Waktu Tanggap Perawat', 'is_active' => true]);

        foreach ([$this->ssc, $this->elsewhere, $this->impUnit] as $indicator) {
            $this->publishNewVersion($this->admin, $indicator, $this->sscFields());
        }
        $this->openDraft($this->admin, $this->draftOnly);

        $this->ssc->assignedUnits()->attach($this->ns3b->id);
        $this->draftOnly->assignedUnits()->attach($this->ns3b->id);
        $this->impUnit->assignedUnits()->attach($this->ns3b->id);
        $this->elsewhere->assignedUnits()->attach($this->other->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sscFields(): array
    {
        $yesNo = fn (string $key, string $label) => ['label' => $label, 'type' => 'yes_no', 'key' => $key, 'section' => 'Surgical Safety Checklist', 'is_required' => true];

        return [
            ['label' => 'Nama Pasien', 'type' => 'text', 'key' => 'patient_name', 'is_required' => true],
            ['label' => 'Ruangan', 'type' => 'select', 'key' => 'room', 'is_required' => true, 'options' => [
                ['label' => 'Lantai 3 Gedung B', 'value' => 'l3b'],
                ['label' => 'Ruang OK', 'value' => 'ok'],
            ]],
            $yesNo('sign_in', 'Anestesi/Sign In'),
            $yesNo('time_out', 'Perawat/Time Out'),
            $yesNo('sign_out', 'DPJP/Sign Out'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sscInput(array $overrides = []): array
    {
        return [
            'indicator_id' => $this->ssc->id,
            'observation_date' => now()->toDateString(),
            'patient_name' => 'Tn. Budi',
            'room' => 'ok',
            'sign_in' => '1',
            'time_out' => '1',
            'sign_out' => '0',
            ...$overrides,
        ];
    }

    public function test_pic_unit_sees_inm_and_mapped_imp_rs_on_the_input_page(): void
    {
        $response = $this->actingAs($this->picUnit)->get(route('observations.create'))->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id, $this->ssc->id, $this->draftOnly->id, $this->impUnit->id],
            $response->viewData('indicators')->pluck('id')->all(),
        );
        $statuses = $response->viewData('indicators')->pluck('input_status', 'id');
        $this->assertSame('ready', $statuses[$this->inm[ObservationForms::PPE]->id]);
        $this->assertSame('ready', $statuses[$this->ssc->id]);
        $this->assertSame('unpublished', $statuses[$this->draftOnly->id]);
        $this->assertSame('closed', $statuses[$this->impUnit->id]);

        $response->assertSee('IMP RS')
            ->assertSee(route('observations.create.indicator', $this->ssc))
            // Draft only: listed with its status, no link.
            ->assertSee('Kepatuhan Irigasi Post TURP')
            ->assertSee('Formulir belum terbit')
            ->assertDontSee(route('observations.create.indicator', $this->draftOnly))
            // IMP Unit mapped to the unit: listed, not open for input.
            ->assertSee('Waktu Tanggap Perawat')
            ->assertSee('Belum dibuka untuk input')
            ->assertDontSee(route('observations.create.indicator', $this->impUnit))
            // Another unit's IMP RS is not listed at all.
            ->assertDontSee('Kepatuhan Hand Hygiene Rawat Jalan')
            // No form until an indicator is chosen.
            ->assertDontSee('id="observation-modal"', false);
    }

    public function test_category_filter_shows_only_that_category(): void
    {
        $ids = fn (?string $category) => $this->actingAs($this->picUnit)
            ->get(route('observations.create', $category ? ['kategori' => $category] : []))
            ->assertOk()
            ->viewData('indicators')->pluck('id')->sort()->values()->all();
        $sorted = fn (array $ids) => collect($ids)->sort()->values()->all();

        $inm = [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id];

        $this->assertSame($sorted([...$inm, $this->ssc->id, $this->draftOnly->id, $this->impUnit->id]), $ids(null));
        $this->assertSame($sorted($inm), $ids('inm'));
        $this->assertSame($sorted([$this->ssc->id, $this->draftOnly->id]), $ids('imp-rs'));
        $this->assertSame([$this->impUnit->id], $ids('imp-unit'));
        // Unknown values fall back to "Semua"; the unit scope always applies.
        $this->assertSame($ids(null), $ids('lainnya'));
        $this->assertNotContains($this->elsewhere->id, $ids('imp-rs'));

        // Tabs with counts; the active one is marked.
        $response = $this->actingAs($this->picUnit)->get(route('observations.create', ['kategori' => 'imp-rs']));
        $this->assertSame(
            ['semua' => 5, 'inm' => 2, 'imp-rs' => 2, 'imp-unit' => 1],
            $response->viewData('categoryTabs')->map(fn ($tab) => $tab['count'])->all(),
        );
        $response->assertSee('data-category-tab="imp-rs"', false)
            ->assertSeeInOrder(['aria-current="page"', 'data-category-tab="imp-rs"'], false)
            // Cards keep the tab, so closing the form returns to it.
            ->assertSee(route('observations.create.indicator', [$this->ssc, 'kategori' => 'imp-rs']));

        $this->actingAs($this->picUnit)->get(route('observations.create', ['kategori' => 'imp-unit']))
            ->assertSee('Belum dibuka untuk input');
    }

    public function test_choosing_an_indicator_opens_its_form_in_a_modal(): void
    {
        $response = $this->actingAs($this->picUnit)
            ->get(route('observations.create.indicator', [$this->ssc, 'kategori' => 'imp-rs']))
            ->assertOk();

        $response->assertSee('id="observation-modal"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSeeInOrder(['id="observation-modal-title"', 'Kepatuhan Surgical Safety Checklist'], false)
            // Dynamic Form Builder fields, buttons, and the tab to return to.
            ->assertSeeInOrder(['Nama Pasien', 'Ruangan', 'Anestesi/Sign In', 'Perawat/Time Out', 'DPJP/Sign Out'])
            ->assertSee('Simpan Observasi')
            ->assertSee('Batal')
            ->assertSee('name="kategori" value="imp-rs"', false)
            ->assertSee(route('observations.create', ['kategori' => 'imp-rs']))
            // The chosen card is marked.
            ->assertSeeInOrder(['data-indicator-card="'.$this->ssc->id.'"', 'aria-current="true"', 'Sedang diisi'], false);

        // Only the modal holds the form: one form posting to store.
        $this->assertSame(1, substr_count($response->getContent(), 'action="'.route('observations.store').'"'));
    }

    public function test_saving_closes_the_modal_with_a_success_message(): void
    {
        $this->actingAs($this->picUnit)
            ->from(route('observations.create.indicator', [$this->ssc, 'kategori' => 'imp-rs']))
            ->post(route('observations.store'), $this->sscInput(['kategori' => 'imp-rs']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('observations.create', ['kategori' => 'imp-rs']))
            ->assertSessionHas('success', 'Observasi "Kepatuhan Surgical Safety Checklist" berhasil disimpan.');

        $this->assertDatabaseCount('observations', 1);

        // The page it lands on has the list, the toast, and no open form.
        $this->actingAs($this->picUnit)
            ->withSession(['success' => 'Observasi "Kepatuhan Surgical Safety Checklist" berhasil disimpan.'])
            ->get(route('observations.create', ['kategori' => 'imp-rs']))
            ->assertSee('berhasil disimpan')
            ->assertDontSee('id="observation-modal"', false);
    }

    public function test_failed_save_reopens_the_modal_with_errors_and_answers(): void
    {
        $from = route('observations.create.indicator', [$this->ssc, 'kategori' => 'imp-rs']);

        $this->actingAs($this->picUnit)->from($from)
            ->post(route('observations.store'), $this->sscInput(['patient_name' => '', 'room' => 'ok', 'kategori' => 'imp-rs']))
            ->assertRedirect($from)
            ->assertSessionHasErrors('patient_name');
        $this->assertDatabaseCount('observations', 0);

        // Following the redirect: the modal is open again, with the error and the answers given.
        $this->actingAs($this->picUnit)->get($from)
            ->assertSee('id="observation-modal"', false)
            ->assertSee('Periksa kembali isian yang ditandai.')
            ->assertSee('\u0022dirty\u0022:true', false)
            ->assertSee('<option value="ok" selected', false);
    }

    /** An IMP RS indicator whose form has a long room list (searchable). */
    private function roomsIndicator(): Indicator
    {
        $rooms = ['Bougenville', 'Cempaka', 'Dahlia', 'Edelweis', 'Flamboyan', 'Gardenia', 'Kenanga', 'Melati', 'Teratai'];
        $indicator = Indicator::create(['indicator_category_id' => $this->ssc->indicator_category_id, 'name' => 'Kepatuhan Identifikasi Ruangan', 'is_active' => true]);

        $this->publishNewVersion($this->admin, $indicator, [
            ['label' => 'Nama Pasien', 'type' => 'text', 'key' => 'patient_name', 'is_required' => true],
            ['label' => 'Ruangan', 'type' => 'select', 'key' => 'ward', 'is_required' => true, 'placeholder' => 'Pilih ruangan…',
                'options' => array_map(fn (string $room, int $i) => ['label' => "Ruang {$room}", 'value' => 'r'.($i + 1)], $rooms, array_keys($rooms))],
        ]);
        $indicator->assignedUnits()->attach($this->ns3b->id);

        return $indicator;
    }

    public function test_long_dropdowns_are_searchable_and_keep_the_choice(): void
    {
        $rooms = $this->roomsIndicator();
        $form = route('observations.create.indicator', [$rooms, 'kategori' => 'imp-rs']);

        // Nine rooms: a searchable dropdown, teleported above the modal, required like a select.
        $this->actingAs($this->picUnit)->get($form)->assertOk()
            ->assertSee('role="combobox"', false)
            ->assertSee('<input type="hidden" name="ward" value=""', false)
            ->assertSee('Cari ruangan…')
            ->assertSee('Pilih ruangan…')
            ->assertSee('Ruang Kenanga')
            ->assertSee('id="ward-panel"', false)
            ->assertSee('z-[70]', false)
            ->assertSee('x-on:invalid="onInvalid($event)"', false)
            ->assertDontSee('<select name="ward"', false);

        // Two rooms (the SSC form): still a plain dropdown.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->ssc))
            ->assertSee('<select name="room"', false)
            ->assertDontSee('role="combobox"', false);

        // A failed save comes back with the chosen room still chosen.
        $this->actingAs($this->picUnit)->from($form)
            ->post(route('observations.store'), ['indicator_id' => $rooms->id, 'observation_date' => now()->toDateString(), 'patient_name' => '', 'ward' => 'r7', 'kategori' => 'imp-rs'])
            ->assertRedirect($form)
            ->assertSessionHasErrors('patient_name')
            ->assertSessionDoesntHaveErrors('ward');

        $html = $this->actingAs($this->picUnit)->get($form)->assertOk()
            ->assertSee('<input type="hidden" name="ward" value="r7"', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/x-text="selected \? selected.label : placeholder">Ruang Kenanga</', $html);

        // Unknown values are refused as before; a valid choice is stored.
        $this->actingAs($this->picUnit)->post(route('observations.store'), ['indicator_id' => $rooms->id, 'observation_date' => now()->toDateString(), 'patient_name' => 'Ny. A', 'ward' => 'r99'])
            ->assertSessionHasErrors('ward');
        $this->actingAs($this->picUnit)->post(route('observations.store'), ['indicator_id' => $rooms->id, 'observation_date' => now()->toDateString(), 'patient_name' => 'Ny. A', 'ward' => 'r7'])
            ->assertSessionHasNoErrors();

        $value = ObservationValue::whereHas('field', fn ($q) => $q->where('key', 'ward'))->with('option')->sole();
        $this->assertSame('Ruang Kenanga', $value->option->label);

        // Editing preselects it.
        $this->actingAs($this->picUnit)->get(route('observations.edit', Observation::sole()))->assertOk()
            ->assertSee('<input type="hidden" name="ward" value="r7"', false)
            ->assertSee('Ruang Kenanga');
    }

    public function test_modal_animates_scrolls_inside_and_keeps_the_page_position(): void
    {
        $html = $this->actingAs($this->picUnit)->get(route('observations.create.indicator', [$this->ssc, 'kategori' => 'imp-rs']))->assertOk()
            // Opens after the first paint (no flash) with a light fade and scale; closing waits for the transition.
            ->assertSee('x-data="dialog(', false)
            ->assertSee('x-show="visible" x-cloak', false)
            ->assertSee('opacity-0 translate-y-6 sm:translate-y-0 sm:scale-[0.97]', false)
            ->assertSee('motion-reduce:transition-none', false)
            // Tab stays in the dialog; a click that only closes a dropdown keeps the modal open.
            ->assertSee('x-on:keydown.tab="trap($event)"', false)
            ->assertSee('x-on:click="closeFromBackdrop()"', false)
            // Long forms scroll inside the modal, not the page.
            ->assertSee('data-dialog-body class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain', false)
            // The list keeps its position across opening, saving and closing.
            ->assertSee('data-keep-scroll', false)
            ->assertSee('simutu:observation-scroll')
            ->getContent();

        $this->assertStringContainsString('\u0022closeUrl\u0022:\u0022'.str_replace('/', '\\\\\\/', route('observations.create', ['kategori' => 'imp-rs'])).'\u0022', $html);
        $this->assertStringContainsString('\u0022dirty\u0022:false', $html);
    }

    public function test_dashboard_offers_input_for_recordable_indicators(): void
    {
        $response = $this->actingAs($this->picUnit)->get(route('dashboard'))->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id, $this->ssc->id],
            $response->viewData('inputIds')->all(),
        );
        $this->assertSame([$this->draftOnly->id], $response->viewData('unpublishedIds')->all());
        $response->assertSee(route('observations.create.indicator', $this->ssc))
            ->assertDontSee(route('observations.create.indicator', $this->draftOnly))
            ->assertDontSee(route('observations.create.indicator', $this->impUnit));
    }

    public function test_imp_rs_form_opens_and_saves_through_the_observation_workflow(): void
    {
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->ssc))
            ->assertOk()
            ->assertSee('Kepatuhan Surgical Safety Checklist')
            ->assertSee('Nama Pasien')
            ->assertSee('Anestesi/Sign In')
            ->assertSee('Ruang OK');

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->sscInput([
            'unit_id' => $this->other->id, // ignored: the unit comes from the account
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $observation = Observation::sole();
        $this->assertSame($this->ssc->id, $observation->indicator_id);
        $this->assertSame($this->ssc->publishedFormVersion->id, $observation->indicator_form_version_id);
        $this->assertSame($this->ns3b->id, $observation->unit_id);
        $this->assertSame($this->picUnit->id, $observation->user_id);

        $values = ObservationValue::with('field')->where('observation_id', $observation->id)->get()->mapWithKeys(fn ($v) => [$v->field->key => $v->value]);
        $this->assertSame('Tn. Budi', $values['patient_name']);
        $this->assertSame('ok', $values['room']);
        $this->assertSame('0', (string) $values['sign_out']);

        // History, detail, correction and deletion follow the existing rules.
        $this->actingAs($this->picUnit)->get(route('observations.index', ['kategori' => 'imp-rs']))->assertOk()
            ->assertViewHas('indicators', fn ($indicators) => $indicators->contains('id', $this->ssc->id));
        $this->actingAs($this->picUnit)->get(route('observations.show', $observation))->assertOk()->assertSee('Tn. Budi');
        $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))->assertOk();
        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), $this->sscInput(['patient_name' => 'Ny. Sari']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->picUnit)->get(route('observations.show', $observation))->assertSee('Ny. Sari');

        // PIC Mutu and Admin see it as before; the Excel report includes it.
        $picMutu = User::factory()->picMutu()->create();
        $this->actingAs($picMutu)->get(route('observations.show', $observation))->assertOk();
        $report = ['kategori' => 'imp-rs', 'indicator_id' => $this->ssc->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()];
        $this->actingAs($this->admin)->get(route('observations.index', $report))->assertOk()->assertSee('Kepatuhan Surgical Safety Checklist')->assertSee('Ny. Sari');
        $this->actingAs($picMutu)->get(route('observations.export', ['format' => 'excel', ...$report]))->assertOk();
    }

    public function test_imp_rs_answers_are_validated(): void
    {
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->sscInput([
            'patient_name' => '',
            'room' => 'not-an-option',
            'sign_in' => null,
        ]))->assertSessionHasErrors(['patient_name', 'room', 'sign_in']);

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_indicators_outside_the_unit_mapping_cannot_be_recorded(): void
    {
        // Another unit's IMP RS: forbidden by URL, refused on submit.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->elsewhere))->assertForbidden();
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->sscInput(['indicator_id' => $this->elsewhere->id]))
            ->assertSessionHasErrors('indicator_id');

        // Mapped IMP RS without a published form.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->draftOnly))->assertNotFound();
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->sscInput(['indicator_id' => $this->draftOnly->id]))
            ->assertSessionHasErrors('indicator_id');

        // IMP Unit, even when mapped and published.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->impUnit))->assertNotFound();
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->sscInput(['indicator_id' => $this->impUnit->id]))
            ->assertSessionHasErrors('indicator_id');

        // Inactive IMP RS.
        $this->ssc->update(['is_active' => false]);
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->ssc))->assertNotFound();

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_another_units_imp_rs_observation_stays_out_of_reach(): void
    {
        $nurse = User::factory()->picUnit($this->other)->create();
        $this->actingAs($nurse)->post(route('observations.store'), $this->sscInput(['indicator_id' => $this->elsewhere->id]))
            ->assertSessionHasNoErrors();
        $observation = Observation::sole();

        $this->actingAs($this->picUnit)->get(route('observations.show', $observation))->assertForbidden();
        $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))->assertForbidden();
        $this->actingAs($this->picUnit)->delete(route('observations.destroy', $observation))->assertForbidden();
        $this->actingAs($this->picUnit)->get(route('observations.index'))->assertDontSee('Kepatuhan Hand Hygiene Rawat Jalan');
    }

    public function test_inm_input_is_unchanged_and_other_roles_cannot_record(): void
    {
        $this->actingAs($this->picUnit)->post(route('observations.store'), [
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('observations', 1);

        // PIC Mutu still reviews only; the IMP RS form is no exception.
        $picMutu = User::factory()->picMutu()->create();
        $this->actingAs($picMutu)->get(route('observations.create'))->assertForbidden();
        $this->actingAs($picMutu)->get(route('observations.create.indicator', $this->ssc))->assertForbidden();
        $this->actingAs($picMutu)->get(route('dashboard'))->assertOk()->assertViewHas('inputIds', fn ($ids) => $ids->isEmpty());

        // Admin's dashboard has no per-indicator input buttons, as before.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertViewHas('inputIds', fn ($ids) => $ids->isEmpty());
    }
}

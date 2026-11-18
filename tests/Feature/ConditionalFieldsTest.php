<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jotform\JotformExport;
use App\Support\Jotform\JotformImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * Conditional fields: questions shown only for some answers of another
 * single-choice field (the patient survey's "Jenis Pelayanan").
 */
class ConditionalFieldsTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, RefreshDatabase;

    private User $admin;

    private User $picUnit;

    private Unit $unit;

    private Indicator $survey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'RJ', 'name' => 'Rawat Jalan', 'is_active' => true]);
        $this->picUnit = User::factory()->picUnit($this->unit)->create();

        $inm = IndicatorCategory::where('code', 'INM')->sole();
        $this->survey = Indicator::create(['indicator_category_id' => $inm->id, 'name' => 'Survei Kepuasan Pasien', 'is_active' => true]);
        $this->survey->assignedUnits()->attach($this->unit->id);
    }

    /** Published through the Form Builder, conditions included. */
    private function publishSurvey(): IndicatorFormVersion
    {
        $when = fn (string $key, array $values) => ['visible_when_key' => $key, 'visible_when_values' => $values];
        $draft = $this->openDraft($this->admin, $this->survey);

        $this->saveDraft($this->admin, $draft, [
            ['key' => 'pekerjaan', 'label' => 'Pekerjaan', 'type' => 'radio', 'is_required' => true, 'options' => [['value' => 'pns', 'label' => 'PNS'], ['value' => 'lainnya', 'label' => 'LAINNYA']]],
            ['key' => 'pekerjaan_lain', 'label' => 'Pekerjaan lainnya', 'type' => 'text', 'is_required' => true, ...$when('pekerjaan', ['lainnya'])],
            ['key' => 'jenis_pelayanan', 'label' => 'Jenis Pelayanan', 'type' => 'radio', 'is_required' => true, 'options' => [
                ['value' => 'rawat_inap', 'label' => 'Rawat Inap'], ['value' => 'rawat_jalan', 'label' => 'Rawat Jalan/Poliklinik'],
                ['value' => 'igd', 'label' => 'IGD'], ['value' => 'lainnya', 'label' => 'Lainnya'],
            ]],
            ['key' => 'kamar', 'label' => 'Kamar/Ruangan', 'type' => 'select', 'section' => 'Rawat Inap', 'is_required' => true,
                'options' => [['value' => 'vip', 'label' => 'Kelas VIP'], ['value' => 'kelas_1', 'label' => 'Kelas 1']], ...$when('jenis_pelayanan', ['rawat_inap'])],
            ['key' => 'waktu_rawat_inap', 'label' => 'Waktu Rawat Inap', 'type' => 'date', 'section' => 'Rawat Inap', 'is_required' => true, ...$when('jenis_pelayanan', ['rawat_inap'])],
            ['key' => 'poliklinik', 'label' => 'Poliklinik', 'type' => 'select', 'section' => 'Rawat Jalan', 'is_required' => true,
                'options' => [['value' => 'poli_anak', 'label' => 'POLI ANAK'], ['value' => 'poli_mata', 'label' => 'POLI MATA']], ...$when('jenis_pelayanan', ['rawat_jalan'])],
            ['key' => 'waktu_ke_igd', 'label' => 'Waktu ke IGD', 'type' => 'date', 'section' => 'IGD', 'is_required' => true, ...$when('jenis_pelayanan', ['igd'])],
            ['key' => 'pelayanan', 'label' => 'Pelayanan', 'type' => 'text', 'section' => 'Pelayanan Lainnya', 'is_required' => true, ...$when('jenis_pelayanan', ['lainnya'])],
            ['key' => 'kepuasan', 'label' => 'Kepuasan', 'type' => 'radio', 'section' => 'Penilaian', 'is_required' => true, 'options' => [['value' => 'puas', 'label' => 'Puas'], ['value' => 'tidak_puas', 'label' => 'Tidak puas']]],
        ], 'publish')->assertSessionHasNoErrors();

        return $this->survey->publishedFormVersion()->with('fields.options')->firstOrFail();
    }

    /** The answers every patient gives, plus those of their service. */
    private function answers(string $service, array $extra = []): array
    {
        return [
            'indicator_id' => $this->survey->id,
            'observation_date' => now()->toDateString(),
            'pekerjaan' => 'pns',
            'jenis_pelayanan' => $service,
            'kepuasan' => 'puas',
            ...$extra,
        ];
    }

    private const BRANCHES = [
        'rawat_inap' => ['kamar' => 'vip', 'waktu_rawat_inap' => '2026-11-10'],
        'rawat_jalan' => ['poliklinik' => 'poli_mata'],
        'igd' => ['waktu_ke_igd' => '2026-11-11'],
        'lainnya' => ['pelayanan' => 'Laboratorium'],
    ];

    /** @return array<string, array{string}> */
    public static function services(): array
    {
        return ['Rawat Inap' => ['rawat_inap'], 'Rawat Jalan/Poliklinik' => ['rawat_jalan'], 'IGD' => ['igd'], 'Lainnya' => ['lainnya']];
    }

    // --- saving ----------------------------------------------------------------------

    #[DataProvider('services')]
    public function test_each_service_requires_and_stores_only_its_own_questions(string $service): void
    {
        $this->publishSurvey();
        $own = self::BRANCHES[$service];
        $others = array_merge(...array_values(array_diff_key(self::BRANCHES, [$service => true])));

        // Its own questions are required...
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers($service))
            ->assertSessionHasErrors(array_keys($own));
        $this->assertDatabaseCount('observations', 0);

        // ...the other services' are neither required nor validated (even when invalid)...
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers($service, [
            ...$own,
            ...array_map(fn () => 'nilai-tidak-valid', $others),
        ]))->assertSessionHasNoErrors();

        // ...nor stored: the record holds exactly the answers for this service.
        $stored = Observation::sole()->values()->with('field')->get()->pluck('value', 'field.key')->all();
        $this->assertEqualsCanonicalizing(['pekerjaan', 'jenis_pelayanan', 'kepuasan', ...array_keys($own)], array_keys($stored));
        foreach ($own as $key => $value) {
            $this->assertSame($value, $stored[$key]);
        }
    }

    public function test_a_question_follows_its_own_answer_and_invalid_triggers_are_refused(): void
    {
        $this->publishSurvey();

        // "Pekerjaan lainnya" only for LAINNYA.
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers('igd', [...self::BRANCHES['igd'], 'pekerjaan' => 'lainnya']))
            ->assertSessionHasErrors(['pekerjaan_lain' => 'Pekerjaan lainnya wajib diisi.']);
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers('igd', [...self::BRANCHES['igd'], 'pekerjaan' => 'lainnya', 'pekerjaan_lain' => 'Wiraswasta']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Wiraswasta', Observation::sole()->values()->whereHas('field', fn ($q) => $q->where('key', 'pekerjaan_lain'))->value('value'));

        // An unknown service shows none of the branches, and is refused itself.
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers('apotek'))
            ->assertSessionHasErrors('jenis_pelayanan')
            ->assertSessionDoesntHaveErrors(['kamar', 'poliklinik', 'waktu_ke_igd', 'pelayanan']);
    }

    public function test_changing_the_service_on_edit_removes_the_previous_services_answers(): void
    {
        $this->publishSurvey();
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->answers('rawat_inap', self::BRANCHES['rawat_inap']))->assertSessionHasNoErrors();
        $observation = Observation::sole();

        $this->actingAs($this->picUnit)->put(route('observations.update', $observation), [
            ...$this->answers('rawat_jalan', self::BRANCHES['rawat_jalan']),
            ...self::BRANCHES['rawat_inap'], // still in the form, but hidden
        ])->assertSessionHasNoErrors();

        $keys = $observation->values()->with('field')->get()->pluck('field.key')->all();
        $this->assertContains('poliklinik', $keys);
        $this->assertNotContains('kamar', $keys);
        $this->assertNotContains('waktu_rawat_inap', $keys);
    }

    // --- form ------------------------------------------------------------------------

    public function test_form_marks_conditional_questions_and_shows_the_chosen_service(): void
    {
        $this->publishSurvey();
        $form = route('observations.create.indicator', $this->survey);

        // Nothing chosen yet: every branch hidden, the section headings too.
        $html = $this->actingAs($this->picUnit)->get($form)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-field="kamar" data-visible-when="\{&quot;key&quot;:&quot;jenis_pelayanan&quot;,&quot;values&quot;:\[&quot;rawat_inap&quot;\]\}"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-section\s+hidden\s*>\s*<legend[^>]*>\s*<span[^>]*><\/span>\s*Rawat Inap/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-field="kepuasan"[^>]*hidden/', $html);

        // After a failed save the chosen service's questions are open, the others closed.
        $this->actingAs($this->picUnit)->from($form)->post(route('observations.store'), $this->answers('igd'))->assertRedirect($form);
        $html = $this->actingAs($this->picUnit)->get($form)->getContent();
        $this->assertMatchesRegularExpression('/data-field="waktu_ke_igd" data-visible-when="[^"]+"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-field="kamar" data-visible-when="[^"]+"\s+hidden/', $html);
        $this->assertStringContainsString('Waktu ke IGD wajib diisi.', $html);

        // Mobile layout of the modal: almost full height, Simpan beside Batal.
        $this->assertStringContainsString('h-[96dvh]', $html);
        $this->assertStringContainsString('class="btn btn-primary flex-1 sm:flex-none"', $html);
    }

    // --- form builder ----------------------------------------------------------------

    public function test_builder_keeps_conditions_and_refuses_impossible_ones(): void
    {
        $published = $this->publishSurvey();
        $kamar = $published->fields->firstWhere('key', 'kamar');
        $this->assertSame(['jenis_pelayanan', ['rawat_inap']], [$kamar->visible_when_key, $kamar->visible_when_values]);
        $this->assertNull($published->fields->firstWhere('key', 'kepuasan')->visible_when_key);

        // A new draft starts from the published form, conditions included, and the editor gets them.
        $draft = $this->openDraft($this->admin, $this->survey);
        $this->assertSame(['jenis_pelayanan', ['rawat_inap']], [$draft->fields()->where('key', 'kamar')->first()->visible_when_key, $draft->fields()->where('key', 'kamar')->first()->visible_when_values]);
        $this->actingAs($this->admin)->get(route('form-builder.edit', [$this->survey, $draft]))->assertOk()
            ->assertSee('Tampilkan field ini')
            ->assertSee('\u0022visible_when_key\u0022:\u0022jenis_pelayanan\u0022', false);

        $fields = $this->builderFields($draft);
        $index = array_search('kamar', array_column($fields, 'key'), true);
        $with = function (array $change) use ($fields, $index) {
            $fields[$index] = [...$fields[$index], ...$change];

            return $fields;
        };

        foreach ([
            [['visible_when_key' => 'tidak_ada'], 'tidak ditemukan'],
            [['visible_when_key' => 'kepuasan', 'visible_when_values' => ['puas']], 'sebelum field ini'],
            [['visible_when_key' => 'waktu_rawat_inap', 'visible_when_values' => ['x']], 'sebelum field ini'],
            [['visible_when_key' => 'pekerjaan_lain', 'visible_when_values' => ['x']], 'Dropdown atau Pilihan Tunggal'],
            [['visible_when_values' => ['rawat_inap', 'apotek']], 'Pilihan syarat tampil tidak ada'],
        ] as [$change, $message]) {
            $response = $this->saveDraft($this->admin, $draft, $with($change))->assertSessionHasErrors("fields.{$index}.visible_when_key");
            $this->assertStringContainsString($message, session('errors')->first("fields.{$index}.visible_when_key"));
        }

        // A trigger cannot itself depend on another field.
        $trigger = array_search('jenis_pelayanan', array_column($fields, 'key'), true);
        $chained = $fields;
        $chained[$trigger] = [...$chained[$trigger], 'visible_when_key' => 'pekerjaan', 'visible_when_values' => ['pns']];
        $this->saveDraft($this->admin, $draft, $chained)->assertSessionHasErrors("fields.{$index}.visible_when_key");

        // Clearing the condition makes the question always shown again.
        $this->saveDraft($this->admin, $draft, $with(['visible_when_key' => null, 'visible_when_values' => null]))->assertSessionHasNoErrors();
        $this->assertNull($draft->fields()->where('key', 'kamar')->value('visible_when_key'));
    }

    // --- the imported patient survey -------------------------------------------------

    public function test_imported_patient_survey_with_service_conditions_accepts_every_service(): void
    {
        $definition = JotformExport::find('213401282467047');
        JotformImporter::map($definition, $this->survey);
        $draft = JotformImporter::import($definition, $this->admin)['version'];

        // The same conditions as the reviewed draft: the Jotform questions of each service.
        $fields = $this->builderFields($draft);
        $byExternal = $draft->fields()->with('options')->get()->keyBy('external_id');
        $service = fn (int $option) => $byExternal['6']->options[$option]->value;
        $conditions = [
            '5' => ['4', $byExternal['4']->options->firstWhere('label', 'LAINNYA')->value],
            '53' => ['6', $service(0)], '8' => ['6', $service(0)], '12' => ['6', $service(0)],
            '11' => ['6', $service(1)], '9' => ['6', $service(1)],
            '13' => ['6', $service(2)],
            '14' => ['6', $service(3)], '15' => ['6', $service(3)],
        ];
        $externalIds = $draft->fields()->pluck('external_id', 'key');
        foreach ($fields as &$field) {
            if (isset($conditions[$externalIds[$field['key']]])) {
                [$trigger, $value] = $conditions[$externalIds[$field['key']]];
                $field['visible_when_key'] = $byExternal[$trigger]->key;
                $field['visible_when_values'] = [$value];
            }
        }
        unset($field);
        $this->saveDraft($this->admin, $draft, $fields, 'publish')->assertSessionHasNoErrors();
        $version = $this->survey->publishedFormVersion()->with('fields.options')->firstOrFail();
        $key = fn (string $external) => $version->fields->firstWhere('external_id', $external)->key;
        $option = fn (string $external, int $index) => $version->fields->firstWhere('external_id', $external)->options[$index]->value;

        $common = [
            'indicator_id' => $this->survey->id, 'observation_date' => now()->toDateString(),
            $key('1') => $option('1', 0), $key('2') => '40', $key('3') => $option('3', 2), $key('4') => $option('4', 0),
            $key('42') => 'Tidak ada', $key('43') => 'Pertahankan', $key('44') => $option('44', 0), $key('46') => $option('46', 1),
            $key('50') => '812345', $key('49') => '90',
        ];
        foreach (['32:0', '33:0', '34:0', '35:0', '36:0', '37:0', '38:0', '39:0', '40:0'] as $rating) {
            $common[$key($rating)] = $option($rating, 3);
        }
        $branches = [
            0 => [$key('53') => $option('53', 1), $key('8') => now()->toDateString(), $key('12') => $option('12', 0)],
            1 => [$key('11') => now()->toDateString(), $key('9') => $option('9', 3)],
            2 => [$key('13') => now()->toDateString()],
            3 => [$key('14') => 'Laboratorium', $key('15') => now()->toDateString()],
        ];

        foreach ($branches as $index => $answers) {
            // Without its own questions: refused, and only those are asked for.
            $this->actingAs($this->picUnit)->post(route('observations.store'), [...$common, $key('6') => $option('6', $index)])
                ->assertSessionHasErrors(array_keys($answers));
            $missing = array_keys(session('errors')->getMessages());
            $this->assertEqualsCanonicalizing(array_keys($answers), $missing);

            $this->actingAs($this->picUnit)->post(route('observations.store'), [...$common, $key('6') => $option('6', $index), ...$answers])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(4, Observation::count());
    }
}

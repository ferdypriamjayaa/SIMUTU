<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\IndicatorField;
use App\Models\IndicatorFieldOption;
use App\Models\IndicatorFormVersion;
use App\Models\JotformForm;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jotform\JotformDefinition;
use App\Support\Jotform\JotformExport;
use App\Support\Jotform\JotformImporter;
use App\Support\Jotform\JotformImportException;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * Importing Jotform form definitions. The 14 real exports in JOTFORM-EXPORT
 * are used as they are; broken or unsupported definitions are written to a
 * temporary folder.
 */
class JotformImportTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private const SBAR = '241393736710457';

    private const CPPT = '241342301834446';

    private const VISITE = '241322451430442';

    private const IDENTIFICATION = '241320598106452';

    private const HAND_HYGIENE = '241321670186452';

    /** INM: Kuisioner Survei Kepuasan Pasien (slider, calculation, single-row tables). */
    private const PATIENT_SURVEY = '213401282467047';

    /** @var array<string, Indicator> */
    private array $indicators;

    private User $admin;

    private IndicatorCategory $impRs;

    private ?string $temporary = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicators = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->impRs = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'Indikator Mutu Pelayanan Rumah Sakit']);
        IndicatorCategory::forceCreate(['code' => 'IMP_UNIT', 'slug' => 'imp-unit', 'name' => 'Indikator Mutu Pelayanan Unit']);
    }

    protected function tearDown(): void
    {
        if ($this->temporary) {
            File::deleteDirectory($this->temporary);
        }

        parent::tearDown();
    }

    private function definition(string $formId): JotformDefinition
    {
        return JotformExport::find($formId) ?? $this->fail("Export {$formId} not found.");
    }

    private function newIndicator(string $name = 'Indikator Impor'): Indicator
    {
        return Indicator::create(['indicator_category_id' => $this->impRs->id, 'name' => $name, 'is_active' => true]);
    }

    /** Map through the Admin page: a new IMP RS indicator named after the form. */
    private function mapToNew(string $formId): Indicator
    {
        $this->actingAs($this->admin)->post(route('jotform-import.map', $formId), [
            'target' => 'new',
            'category' => 'imp-rs',
            'name' => $this->definition($formId)->title,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        return JotformForm::where('jotform_form_id', $formId)->sole()->indicator;
    }

    /**
     * Point the importer at a temporary export folder holding one form.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    private function fakeExport(array $questions, string $formId = '999000111222333', string $title = 'Formulir Uji'): void
    {
        $this->temporary ??= storage_path('framework/testing/jotform-'.uniqid());
        $directory = $this->temporary.'/01-UJI';
        File::ensureDirectoryExists($directory);

        $content = [];
        foreach ($questions as $index => $question) {
            $qid = (string) ($question['qid'] ?? $index + 1);
            $content[$qid] = ['qid' => $qid, 'order' => (string) ($index + 1), 'name' => 'q'.$qid, ...$question];
        }

        // Same shape as the real exports: BOM, API envelope, submission counters.
        File::put($directory.'/form.json', "\xEF\xBB\xBF".json_encode(['responseCode' => 200, 'content' => [
            'id' => $formId, 'title' => $title, 'status' => 'ENABLED', 'count' => '987654', 'last_submission' => '2026-01-01 00:00:00',
        ]]));
        File::put($directory.'/questions.json', "\xEF\xBB\xBF".json_encode(['responseCode' => 200, 'content' => $content]));

        config(['services.jotform.export_path' => $this->temporary]);
    }

    // --- parsing --------------------------------------------------------------

    public function test_every_reference_form_definition_can_be_parsed(): void
    {
        $entries = JotformExport::all();

        // Exactly the forms of the reference list (config/jotform.php, every category), each exported once.
        $catalogue = array_merge(...array_map('array_keys', array_values(config('jotform.catalog'))));
        $this->assertEqualsCanonicalizing($catalogue, array_map(fn ($entry) => $entry['definition']?->formId, $entries));

        foreach ($entries as $entry) {
            $definition = $entry['definition'];

            $this->assertNull($entry['error'], "{$entry['directory']}: {$entry['error']}");
            $this->assertTrue($definition->isImportable(), "{$entry['directory']}: ".implode(' ', $definition->errors));
            $this->assertMatchesRegularExpression('/^\d{15}$/', $definition->formId);
            $this->assertNotSame('', $definition->title);
            $this->assertSame('ENABLED', $definition->status);
            $this->assertNotEmpty($definition->fields);

            $results = array_count_values(array_column($definition->questions, 'result'));
            $this->assertArrayNotHasKey('unsupported', $results);

            if ($definition->formId === self::PATIENT_SURVEY) {
                // No heading, button or form date; only the computed score is left out.
                $this->assertSame(['field' => 30, 'skipped' => 1], $results);

                continue;
            }

            // Heading and submit button are never fields; the form date is the built-in one.
            $this->assertSame(2, $results['skipped'] ?? 0, $entry['directory']);
            $this->assertSame(1, $results['builtin'] ?? 0, $entry['directory']);

            foreach ($definition->fields as $field) {
                $this->assertArrayHasKey($field['type'], IndicatorField::TYPES);
                $this->assertNotSame('', $field['label']);
                $this->assertNotSame('', $field['external_id']);
            }
        }

        $this->assertCount(15, array_unique(array_map(fn ($entry) => $entry['definition']->formId, $entries)));
        // 75 fields in the 13 original forms, 5 in the VAS form, 30 in the patient survey.
        $this->assertSame(80 + 30, array_sum(array_map(fn ($entry) => count($entry['definition']->fields), $entries)));
    }

    public function test_questions_keep_their_type_order_required_status_and_options(): void
    {
        $sbar = $this->definition(self::SBAR);

        $this->assertSame('Kepatuhan System SBAR Dalam Reulasi Penerimaan Informasi', $sbar->title);
        $this->assertSame(
            [['Waktu Lapor', 'time', '70'], ['Nama Pemberi Informasi', 'text', '73'], ['Nama Penerima Informasi', 'text', '74'], ['Keterangan Informasi', 'textarea', '72']],
            array_map(fn ($field) => [$field['label'], $field['type'], $field['external_id']], $sbar->fields),
        );
        $this->assertSame([true, true, true, true], array_column($sbar->fields, 'is_required'));

        // Dropdown options, in order.
        $cppt = collect($this->definition(self::CPPT)->fields)->keyBy('external_id');
        $this->assertSame('select', $cppt['65']['type']);
        $this->assertSame(
            ['NICU', 'Hemodialisa', 'ICU', 'Stroke Unit', 'Lantai 2 Gedung A', 'Lantai 3 Gedung A', 'Lantai 4 Gedung A', 'Lantai 2 Gedung B', 'Lantai 3 Gedung B', 'Lantai 4 Gedung B'],
            array_column($cppt['65']['options'], 'label'),
        );
        $this->assertSame('lantai_2_gedung_a', $cppt['65']['options'][4]['value']);

        // A √/☓ matrix becomes one Ya/Tidak field per row, under the matrix title.
        $this->assertSame(['Dokter', 'Perawat/Bidan', 'Gizi'], [$cppt['60:0']['label'], $cppt['60:1']['label'], $cppt['60:2']['label']]);
        $this->assertSame(['yes_no', 'Pengisian CPPT', true], [$cppt['60:1']['type'], $cppt['60:1']['section'], $cppt['60:1']['is_required']]);

        // Other column sets stay as the choices of a single-choice field.
        $this->assertSame(['radio', 'Keterangan', null], [$cppt['63:0']['type'], $cppt['63:0']['label'], $cppt['63:0']['section']]);
        $this->assertSame(['Lengkap', 'Tidak Lengkap'], array_column($cppt['63:0']['options'], 'label'));

        // Date with time, number with hint, optional dropdown.
        $visite = collect($this->definition(self::VISITE)->fields)->keyBy('external_id');
        $this->assertSame('datetime', $visite['59']['type']);
        $this->assertSame('datetime', $visite['72']['type']);
        $this->assertSame(['yes_no', 'Tercapai', true], [$visite['71:0']['type'], $visite['71:0']['label'], $visite['71:0']['is_required']]);
        $this->assertArrayNotHasKey('73', $visite->all());

        $usia = collect($this->definition('241343017820446')->fields)->firstWhere('external_id', '65');
        $this->assertSame(['number', 'Usia', '21thn'], [$usia['type'], $usia['label'], $usia['placeholder']]);

        $handHygiene = collect($this->definition(self::HAND_HYGIENE)->fields);
        $this->assertFalse($handHygiene->firstWhere('external_id', '63')['is_required']);
        $this->assertCount(5, $handHygiene->where('section', '5 MOMEN CUCI TANGAN'));

        // HTML in row labels is removed.
        $simgos = collect($this->definition('241342272638455')->fields)->keyBy('external_id');
        $this->assertSame('Subjective', $simgos['60:0']['label']);
    }

    public function test_unsupported_question_types_block_the_import_with_a_clear_reason(): void
    {
        $this->fakeExport([
            ['type' => 'control_head', 'text' => 'Judul'],
            ['type' => 'control_textbox', 'text' => 'Nama', 'required' => 'Yes'],
            ['type' => 'control_fileupload', 'text' => 'Lampiran'],
            ['type' => 'control_matrix', 'text' => 'Tabel', 'inputType' => 'Text Box', 'mcolumns' => 'A|B', 'mrows' => 'X'],
        ]);

        $definition = JotformExport::all()[0]['definition'];

        $this->assertFalse($definition->isImportable());
        $this->assertCount(2, $definition->errors);
        $this->assertStringContainsString('control_fileupload', $definition->errors[0]);
        $this->assertStringContainsString('Text Box', $definition->errors[1]);

        JotformImporter::map($definition, $this->newIndicator());

        $this->actingAs($this->admin)->get(route('jotform-import.index'))
            ->assertOk()
            ->assertSee('Tidak dapat diimpor')
            ->assertSee('Tidak didukung');

        $this->actingAs($this->admin)->post(route('jotform-import.import', $definition->formId))->assertSessionHas('error');
        $this->assertDatabaseCount('indicator_form_versions', 4);
    }

    public function test_every_supported_field_type_is_imported(): void
    {
        $this->fakeExport([
            ['type' => 'control_datetime', 'text' => 'Tanggal', 'allowTime' => 'No', 'required' => 'Yes'],
            ['type' => 'control_textbox', 'text' => 'Nama', 'required' => 'Yes', 'maxsize' => '50', 'hint' => 'Nama lengkap', 'description' => 'Sesuai KTP', 'defaultValue' => 'Anonim'],
            ['type' => 'control_textarea', 'text' => 'Catatan'],
            ['type' => 'control_number', 'text' => 'Jumlah', 'minValue' => '1', 'maxValue' => '20', 'defaultValue' => '5'],
            ['type' => 'control_datetime', 'text' => 'Tanggal Lahir', 'allowTime' => 'No'],
            ['type' => 'control_datetime', 'text' => 'Waktu Kejadian', 'allowTime' => 'Yes'],
            ['type' => 'control_time', 'text' => 'Jam Lapor'],
            ['type' => 'control_dropdown', 'text' => 'Shift', 'options' => 'Pagi|Siang|Malam', 'selected' => 'Siang'],
            ['type' => 'control_radio', 'text' => 'Profesi', 'options' => 'Dokter|Perawat', 'required' => 'Yes'],
            ['type' => 'control_checkbox', 'text' => 'APD', 'options' => 'Masker|Masker|Sarung Tangan'],
            ['type' => 'control_dropdown', 'text' => 'Ruangan', 'options' => 'A|B', 'multipleSelections' => 'Yes'],
            ['type' => 'control_matrix', 'text' => 'Kepatuhan', 'inputType' => 'Check Box', 'mcolumns' => 'Pagi|Malam', 'mrows' => 'Cuci Tangan|APD', 'required' => 'Require at least one answer'],
            ['type' => 'control_textbox', 'text' => 'Rahasia', 'hidden' => 'Yes'],
            ['type' => 'control_button', 'text' => 'Submit'],
        ]);

        $definition = JotformExport::all()[0]['definition'];
        $this->assertTrue($definition->isImportable(), implode(' ', $definition->errors));

        $indicator = $this->newIndicator();
        JotformImporter::map($definition, $indicator);
        $version = JotformImporter::import($definition, $this->admin)['version'];

        $fields = $version->fields()->with('options')->get();

        $this->assertSame(
            ['text', 'textarea', 'number', 'date', 'datetime', 'time', 'select', 'radio', 'checkbox', 'checkbox', 'checkbox', 'checkbox'],
            $fields->pluck('type')->all(),
        );
        $this->assertSame(range(0, 11), $fields->pluck('sort_order')->all());
        $this->assertSame(
            ['nama', 'catatan', 'jumlah', 'tanggal_lahir', 'waktu_kejadian', 'jam_lapor', 'shift', 'profesi', 'apd', 'ruangan', 'cuci_tangan', 'apd_2'],
            $fields->pluck('key')->all(),
        );
        $this->assertSame(['2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12:0', '12:1'], $fields->pluck('external_id')->all());

        $name = $fields[0];
        $this->assertSame([true, 50, 'Nama lengkap', 'Sesuai KTP', 'Anonim'], [$name->is_required, $name->max_length, $name->placeholder, $name->help_text, $name->default_value]);
        $this->assertSame([1.0, 20.0, '5'], [$fields[2]->min_value, $fields[2]->max_value, $fields[2]->default_value]);
        $this->assertSame(['pagi' => 'Pagi', 'siang' => 'Siang', 'malam' => 'Malam'], $fields[6]->options->pluck('label', 'value')->all());
        $this->assertSame('siang', $fields[6]->default_value);
        $this->assertTrue($fields[7]->is_required);
        $this->assertSame(['masker', 'masker_2', 'sarung_tangan'], $fields[8]->options->pluck('value')->all());
        $this->assertSame(['Kepatuhan', false], [$fields[10]->section, $fields[10]->is_required]);
        $this->assertSame(['Pagi', 'Malam'], $fields[11]->options->pluck('label')->all());

        // The imported draft is a normal form: it previews and opens in the builder.
        $this->actingAs($this->admin)->get(route('form-builder.preview', [$indicator, $version]))
            ->assertOk()
            ->assertSee('type="time" name="jam_lapor"', false)
            ->assertSee('type="datetime-local" name="waktu_kejadian"', false);
        $this->actingAs($this->admin)->get(route('form-builder.edit', [$indicator, $version]))->assertOk();
    }

    public function test_range_slider_becomes_a_number_and_calculations_are_skipped(): void
    {
        $slider = ['type' => 'control_widget', 'builderLabel' => 'Fancy Range Slider', 'frameSrc' => 'http://widgets.jotform.io/rangeSlider/'];

        $this->fakeExport([
            ['type' => 'control_textbox', 'text' => 'Nama'],
            [...$slider, 'text' => 'Berapa poin yang Anda berikan', 'required' => 'Yes', 'typez' => 'single', 'min' => '0', 'max' => '100', 'step' => '1'],
            ['type' => 'control_calculation', 'text' => 'Nilai dari 9 Pertanyaan', 'widgetType' => 'native'],
        ]);

        $definition = JotformExport::all()[0]['definition'];
        $this->assertTrue($definition->isImportable(), implode(' ', $definition->errors));

        $report = collect($definition->questions)->keyBy('qid');
        $this->assertSame('skipped', $report['3']['result']);
        $this->assertStringContainsString('perhitungan otomatis', $report['3']['note']);
        $this->assertStringContainsString('Range slider 0–100', $report['2']['note']);

        JotformImporter::map($definition, $this->newIndicator());
        $fields = JotformImporter::import($definition, $this->admin)['version']->fields()->get();

        // Same question, same scale: a required whole number from 0 to 100.
        $this->assertSame(['text', 'number'], $fields->pluck('type')->all());
        $points = $fields[1];
        $this->assertSame(['Berapa poin yang Anda berikan', true, 0.0, 100.0, false], [$points->label, $points->is_required, $points->min_value, $points->max_value, $points->allow_decimal]);

        // Other widgets, and sliders that ask for a range, still block the import with a reason.
        $this->fakeExport([
            [...$slider, 'text' => 'Rentang', 'typez' => 'double', 'min' => '0', 'max' => '10'],
            ['type' => 'control_widget', 'text' => 'Tanda tangan', 'builderLabel' => 'Smooth Signature', 'frameSrc' => 'https://widgets.jotform.io/signature/'],
        ], '999000111222444');
        $blocked = JotformExport::all()[0]['definition'];

        $this->assertFalse($blocked->isImportable());
        $this->assertStringContainsString('dua penanda', $blocked->errors[0]);
        $this->assertStringContainsString('"Smooth Signature" belum didukung', $blocked->errors[1]);
    }

    public function test_patient_survey_imports_as_an_inm_draft_that_can_collect_answers_once_published(): void
    {
        $definition = $this->definition(self::PATIENT_SURVEY);
        $inm = IndicatorCategory::where('code', 'INM')->sole();
        $survey = Indicator::create(['indicator_category_id' => $inm->id, 'name' => 'Survei Kepuasan Pasien', 'is_active' => true]);

        JotformImporter::map($definition, $survey);
        $draft = JotformImporter::import($definition, $this->admin)['version'];

        // A draft only, with every question, choice and scale of the Jotform form, in order.
        $this->assertTrue($draft->isDraft());
        $this->assertSame(0, $survey->publishedFormVersion()->count());
        $fields = $draft->fields()->with('options')->get()->keyBy('external_id');
        $this->assertCount(30, $fields);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '53', '8', '12', '11', '9', '13', '14', '15'], array_map('strval', $fields->keys()->take(14)->all()));
        $this->assertSame(['Laki-Laki', 'Perempuan'], $fields['1']->options->pluck('label')->all());
        $this->assertSame(['Tidak Sesuai', 'Kurang Sesuai', 'Sesuai', 'Sangat Sesuai'], $fields['32:0']->options->pluck('label')->all());
        $this->assertSame(['Sangat Mahal', 'Cukup Mahal', 'Murah', 'Gratis'], $fields['35:0']->options->pluck('label')->all());
        $this->assertCount(25, $fields['9']->options);
        $this->assertSame(['number', 0.0, 100.0, true], [$fields['49']->type, $fields['49']->min_value, $fields['49']->max_value, $fields['49']->is_required]);
        $this->assertArrayNotHasKey('55', $fields->all()); // the computed score

        // Once an administrator publishes it and maps a unit, a PIC Unit can record answers.
        \App\Support\FormBuilder::publish($draft, $this->admin);
        $unit = Unit::create(['code' => 'RJ', 'name' => 'Rawat Jalan', 'is_active' => true]);
        $survey->assignedUnits()->attach($unit->id);
        $picUnit = User::factory()->picUnit($unit)->create();

        $this->actingAs($picUnit)->get(route('observations.create.indicator', $survey))->assertOk()
            ->assertSee('class="choice-option"', false)
            ->assertSee('Bagaimana pendapat Saudara tentang kewajaran biaya/tarif dalam pelayanan');

        $key = fn (string $external) => $fields[$external]->key;
        $option = fn (string $external, int $index) => $fields[$external]->options[$index]->value;
        $answers = [
            'indicator_id' => $survey->id,
            'observation_date' => now()->toDateString(),
            $key('1') => $option('1', 1), $key('2') => '40', $key('3') => $option('3', 2), $key('4') => $option('4', 4), $key('5') => '-',
            $key('6') => $option('6', 1), $key('53') => $option('53', 3), $key('8') => now()->toDateString(), $key('12') => $option('12', 0),
            $key('11') => now()->toDateString(), $key('9') => $option('9', 2), $key('13') => now()->toDateString(), $key('14') => '-', $key('15') => now()->toDateString(),
            $key('42') => 'Tidak ada', $key('43') => 'Pertahankan', $key('44') => $option('44', 0), $key('46') => $option('46', 1), $key('50') => '0812',
            $key('49') => '90',
        ];
        foreach (['32:0', '33:0', '34:0', '35:0', '36:0', '37:0', '38:0', '39:0', '40:0'] as $rating) {
            $answers[$key($rating)] = $option($rating, 3);
        }

        // The 0–100 scale is enforced.
        $this->actingAs($picUnit)->post(route('observations.store'), [...$answers, $key('49') => '101'])->assertSessionHasErrors($key('49'));

        // As imported, the phone number is a Number field and refuses a leading zero:
        // the reviewed draft makes it a digits-only text field.
        $this->actingAs($picUnit)->post(route('observations.store'), $answers)->assertSessionHasErrors($key('50'));
        $answers[$key('50')] = '812';

        $this->actingAs($picUnit)->post(route('observations.store'), $answers)->assertSessionHasNoErrors();
        $observation = Observation::sole();
        $stored = $observation->values()->with('field', 'option')->get()->keyBy('field.external_id');
        $this->assertSame('Sangat Sesuai', $stored['32:0']->option->label);
        $this->assertSame('90', (string) $stored['49']->value);
    }

    // --- mapping --------------------------------------------------------------

    public function test_no_form_is_mapped_automatically_unless_the_title_matches_exactly(): void
    {
        foreach (JotformExport::all() as $entry) {
            $suggestion = JotformExport::suggest($entry['definition'], Indicator::all());
            $this->assertFalse($suggestion['exact'] ?? false, $entry['directory']);
        }

        // Similar titles are suggested, never applied.
        $suggestion = JotformExport::suggest($this->definition(self::VISITE), Indicator::all());
        $this->assertTrue($suggestion['indicator']->is($this->indicators[ObservationForms::SPECIALIST_VISIT]));
        $this->assertNull(JotformExport::suggest($this->definition(self::SBAR), Indicator::all()));

        // Shared generic words ("Kepatuhan", "Dokter Spesialis") are not a match.
        $this->assertNull(JotformExport::suggest($this->definition('241342272638455'), Indicator::all()));
        $this->assertTrue(JotformExport::suggest($this->definition(self::IDENTIFICATION), Indicator::all())['indicator']
            ->is($this->indicators[ObservationForms::IDENTIFICATION]));

        // A hint is shown but never preselected: the Admin has to choose.
        $html = $this->actingAs($this->admin)->get(route('jotform-import.index'))->assertOk()
            ->assertSee('Judulnya berkaitan dengan indikator')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected/', $html);
        $this->assertStringNotContainsString("target: 'existing'", $html);

        // Mapping onto an indicator whose form is in use is called out.
        JotformImporter::map($this->definition(self::VISITE), $this->indicators[ObservationForms::SPECIALIST_VISIT]);
        $this->actingAs($this->admin)->get(route('jotform-import.index'))->assertSee('Indikator ini sudah memiliki formulir terbit (versi 1)');
        JotformForm::query()->delete();

        $this->actingAs($this->admin)->post(route('jotform-import.import-all'))->assertSessionHas('error');
        $this->assertDatabaseCount('jotform_forms', 0);
        $this->assertDatabaseCount('indicators', 4);

        // An exact title match is used without asking.
        $exact = $this->newIndicator('kepatuhan system SBAR dalam reulasi penerimaan informasi');
        $this->actingAs($this->admin)->post(route('jotform-import.import', self::SBAR))->assertSessionHas('success');
        $this->assertSame($exact->id, JotformForm::sole()->indicator_id);
    }

    public function test_admin_confirms_mapping_to_an_existing_or_a_new_indicator(): void
    {
        $visite = $this->indicators[ObservationForms::SPECIALIST_VISIT];

        $this->actingAs($this->admin)->post(route('jotform-import.map', self::VISITE), ['target' => 'existing', 'indicator_id' => $visite->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($visite->id, JotformForm::where('jotform_form_id', self::VISITE)->sole()->indicator_id);

        $unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'new', 'category' => 'imp-unit', 'name' => 'SBAR IGD', 'unit_id' => $unit->id])
            ->assertSessionHasNoErrors();

        $created = Indicator::where('name', 'SBAR IGD')->sole();
        $this->assertSame([$unit->id, 'IMP_UNIT'], [$created->unit_id, $created->category->code]);
        $this->assertSame($created->id, JotformForm::where('jotform_form_id', self::SBAR)->sole()->indicator_id);

        // Mapping alone creates no form.
        $this->assertDatabaseCount('indicator_form_versions', 4);
    }

    public function test_mapping_never_creates_duplicate_indicators_or_double_pairings(): void
    {
        $bag = 'mapping'.self::SBAR;

        // Missing choices.
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'existing'])
            ->assertSessionHasErrorsIn($bag, 'indicator_id');
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'new', 'category' => 'imp-unit', 'name' => 'X'])
            ->assertSessionHasErrorsIn($bag, 'unit_id');
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'new', 'category' => 'inm', 'name' => 'X'])
            ->assertSessionHasErrorsIn($bag, 'category');

        // A new indicator with a name that already exists in the category.
        $this->newIndicator('Sudah Ada');
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'new', 'category' => 'imp-rs', 'name' => 'Sudah Ada'])
            ->assertSessionHas('error');
        $this->assertSame(1, Indicator::where('name', 'Sudah Ada')->count());

        // Two Jotform forms cannot share one indicator.
        $indicator = $this->mapToNew(self::SBAR);
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::CPPT), ['target' => 'existing', 'indicator_id' => $indicator->id])
            ->assertSessionHas('error');
        $this->assertDatabaseCount('jotform_forms', 1);

        // Mapping the same form again updates the same row.
        $other = $this->newIndicator('Indikator Lain');
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'existing', 'indicator_id' => $other->id])
            ->assertSessionMissing('error');
        $this->assertSame($other->id, JotformForm::sole()->indicator_id);

        // Once imported, the mapping is fixed.
        $this->actingAs($this->admin)->post(route('jotform-import.import', self::SBAR))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('jotform-import.map', self::SBAR), ['target' => 'existing', 'indicator_id' => $indicator->id])
            ->assertSessionHas('error');
        $this->assertSame($other->id, JotformForm::sole()->indicator_id);

        $this->actingAs($this->admin)->post(route('jotform-import.map', '123456789012345'), ['target' => 'existing'])->assertNotFound();
    }

    // --- importing --------------------------------------------------------------

    public function test_every_exported_form_can_be_imported_as_a_draft(): void
    {
        $exports = JotformExport::all();
        $forms = count($exports);
        $this->assertSame(15, $forms); // 14 IMP RS + the INM patient satisfaction survey

        foreach ($exports as $entry) {
            $this->mapToNew($entry['definition']->formId);
        }

        $this->assertSame(4 + $forms, Indicator::count());

        $this->actingAs($this->admin)->post(route('jotform-import.import-all'))
            ->assertRedirect(route('jotform-import.index'))
            ->assertSessionHas('success', "{$forms} formulir berhasil diimpor sebagai draf.");

        $this->assertSame($forms, JotformForm::whereNotNull('indicator_form_version_id')->count());
        $this->assertSame(80 + 30, (int) JotformForm::sum('question_count'));

        foreach (JotformForm::with('formVersion.fields.options', 'indicator')->get() as $record) {
            $definition = $this->definition($record->jotform_form_id);
            $version = $record->formVersion;

            $this->assertTrue($version->isDraft());
            $this->assertSame(1, $version->version);
            $this->assertSame($this->admin->id, $version->created_by);
            $this->assertSame($record->indicator_id, $version->indicator_id);
            $this->assertSame($definition->hash, $record->definition_hash);

            // Labels, types, required status, order and options match the definition.
            $this->assertSame(
                array_map(fn ($field) => [$field['external_id'], $field['label'], $field['type'], $field['is_required'], $field['section'], array_column($field['options'], 'label')], $definition->fields),
                $version->fields->map(fn (IndicatorField $field) => [$field->external_id, $field->label, $field->type, $field->is_required, $field->section, $field->options->pluck('label')->all()])->all(),
            );
            $this->assertSame($version->fields->pluck('key')->unique()->count(), $version->fields->count());
        }

        // Nothing was published, and no observation was created.
        $this->assertSame(4, IndicatorFormVersion::where('status', IndicatorFormVersion::STATUS_PUBLISHED)->count());
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('observation_values', 0);

        $this->actingAs($this->admin)->get(route('jotform-import.index'))->assertOk()->assertSee('Sudah diimpor')->assertDontSee('Perlu pemetaan');
    }

    public function test_importing_twice_creates_no_duplicate_forms_or_fields(): void
    {
        $indicator = $this->mapToNew(self::CPPT);

        $this->actingAs($this->admin)->post(route('jotform-import.import', self::CPPT))->assertSessionHas('success');
        $fieldIds = IndicatorField::pluck('id')->all();
        $counts = [IndicatorFormVersion::count(), IndicatorField::count(), Indicator::count(), JotformForm::count()];

        $this->actingAs($this->admin)->post(route('jotform-import.import', self::CPPT))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('jotform-import.import-all'))->assertSessionHas('error');
        $this->assertSame(JotformImporter::UNCHANGED, JotformImporter::import($this->definition(self::CPPT), $this->admin)['status']);

        $this->assertSame($counts, [IndicatorFormVersion::count(), IndicatorField::count(), Indicator::count(), JotformForm::count()]);
        $this->assertSame($fieldIds, IndicatorField::pluck('id')->all());
        $this->assertSame(1, $indicator->formVersions()->count());
    }

    public function test_changed_definition_refreshes_the_draft_and_starts_a_new_version_once_published(): void
    {
        $this->fakeExport([
            ['qid' => '10', 'type' => 'control_textbox', 'text' => 'Nama Pasien', 'required' => 'Yes'],
            ['qid' => '11', 'type' => 'control_dropdown', 'text' => 'Ruangan', 'options' => 'A|B'],
        ]);
        $first = JotformExport::all()[0]['definition'];
        $indicator = $this->newIndicator();
        JotformImporter::map($first, $indicator);
        $draft = JotformImporter::import($first, $this->admin)['version'];

        // Admin renames a key in the builder; the question keeps it on re-import.
        $draft->fields()->where('external_id', '10')->update(['key' => 'patient_name']);

        $this->fakeExport([
            ['qid' => '11', 'type' => 'control_dropdown', 'text' => 'Ruang Rawat', 'options' => 'A|B|C'],
            ['qid' => '10', 'type' => 'control_textbox', 'text' => 'Nama Lengkap Pasien', 'required' => 'No'],
            ['qid' => '12', 'type' => 'control_number', 'text' => 'Usia'],
        ]);
        $second = JotformExport::all()[0]['definition'];
        $this->assertNotSame($first->hash, $second->hash);

        $result = JotformImporter::import($second, $this->admin);

        $this->assertSame(JotformImporter::IMPORTED, $result['status']);
        $this->assertTrue($result['version']->is($draft));
        $this->assertSame(1, $indicator->formVersions()->count());
        $this->assertSame(
            ['ruangan' => 'Ruang Rawat', 'patient_name' => 'Nama Lengkap Pasien', 'usia' => 'Usia'],
            $draft->fields()->pluck('label', 'key')->all(),
        );
        $this->assertSame(3, $draft->fields()->where('key', 'ruangan')->sole()->options()->count());

        // After publishing, the next change becomes version 2 and version 1 is untouched.
        $this->actingAs($this->admin)->post(route('form-builder.publish', [$indicator, $draft]))->assertSessionHasNoErrors();
        $this->fakeExport([['qid' => '10', 'type' => 'control_textbox', 'text' => 'Nama Pasien']]);

        $third = JotformImporter::import(JotformExport::all()[0]['definition'], $this->admin)['version'];

        $this->assertSame([2, true], [$third->version, $third->isDraft()]);
        $this->assertSame(['patient_name'], $third->fields()->pluck('key')->all());
        $this->assertTrue($draft->fresh()->isPublished());
        $this->assertSame(3, $draft->fields()->count());
        $this->assertSame($third->id, JotformForm::sole()->indicator_form_version_id);
    }

    public function test_import_does_not_overwrite_a_draft_the_admin_is_working_on(): void
    {
        $visite = $this->indicators[ObservationForms::SPECIALIST_VISIT];
        $this->actingAs($this->admin)->post(route('form-builder.draft.store', $visite));
        $draft = $visite->draftFormVersion()->firstOrFail();
        $fieldCount = $draft->fields()->count();

        JotformImporter::map($this->definition(self::VISITE), $visite);

        $this->actingAs($this->admin)->post(route('jotform-import.import', self::VISITE))->assertSessionHas('error');

        $this->assertSame($fieldCount, $draft->fields()->count());
        $this->assertNull(JotformForm::sole()->indicator_form_version_id);
        $this->assertSame(2, $visite->formVersions()->count());
    }

    public function test_import_into_an_indicator_with_data_keeps_its_published_form_and_used_fields(): void
    {
        $indicator = $this->indicators[ObservationForms::IDENTIFICATION];
        $unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);
        $this->assignIndicators($unit, $this->indicators);
        $picUnit = User::factory()->picUnit($unit)->create();

        $this->actingAs($picUnit)->post(route('observations.store'), [
            'indicator_id' => $indicator->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
        ])->assertSessionHasNoErrors();

        $published = $indicator->publishedFormVersion;
        $publishedKeys = $published->fields()->pluck('key')->all();

        $this->actingAs($this->admin)->post(route('jotform-import.map', self::IDENTIFICATION), ['target' => 'existing', 'indicator_id' => $indicator->id]);
        $this->actingAs($this->admin)->post(route('jotform-import.import', self::IDENTIFICATION))->assertSessionHas('success');

        // The form in use and the recorded observation are untouched.
        $this->assertTrue($indicator->publishedFormVersion()->first()->is($published));
        $this->assertSame($publishedKeys, $published->fields()->pluck('key')->all());
        $this->assertSame($published->id, Observation::sole()->indicator_form_version_id);
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $indicator))->assertOk()->assertDontSee('Ruangan');

        // The draft holds the Jotform fields, plus the fields with data, switched off.
        $draft = $indicator->draftFormVersion()->firstOrFail();
        $this->assertSame(2, $draft->version);

        $active = $draft->fields()->where('is_active', true)->pluck('label')->all();
        $this->assertSame(['Nama Petugas', 'Ruangan', 'Nama Pasien', 'Pemberian Obat Inj/Po', 'Transfusi Darah/Plasma', 'Nama', 'Tanggal Lahir'], $active);

        $carried = $draft->fields()->where('is_active', false)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['officer_name', 'patient_name', 'medication_compliant', 'procedure_compliant', 'patient_name_verified', 'birth_date_verified'],
            $carried,
        );
        $this->assertEmpty(array_intersect($draft->fields()->where('is_active', true)->pluck('key')->all(), $carried));

        // It can be saved and published from the form builder like any draft.
        $this->actingAs($this->admin)->get(route('form-builder.edit', [$indicator, $draft]))->assertOk();
        $this->actingAs($this->admin)->post(route('form-builder.publish', [$indicator, $draft]))->assertSessionHasNoErrors();
        $this->actingAs($picUnit)->get(route('observations.show', Observation::sole()))->assertOk()->assertSee('Taufik Hidayat');
    }

    public function test_failed_import_rolls_back_completely(): void
    {
        $indicator = $this->mapToNew(self::CPPT);
        $before = [IndicatorFormVersion::count(), IndicatorField::count(), IndicatorFieldOption::count()];

        // Fail in the middle of the import, after the draft and some fields exist.
        IndicatorField::creating(function (IndicatorField $field) {
            if ($field->external_id === '60:2') {
                throw new \RuntimeException('Simulated failure.');
            }
        });

        try {
            $this->actingAs($this->admin)->post(route('jotform-import.import', self::CPPT))
                ->assertSessionHas('error')
                ->assertSessionHas('jotformResults.'.self::CPPT.'.ok', false);
        } finally {
            IndicatorField::flushEventListeners();
        }

        $this->assertSame($before, [IndicatorFormVersion::count(), IndicatorField::count(), IndicatorFieldOption::count()]);
        $this->assertSame(0, $indicator->formVersions()->count());

        $record = JotformForm::sole();
        $this->assertNull($record->indicator_form_version_id);
        $this->assertNull($record->definition_hash);
        $this->assertNull($record->imported_at);

        // The same import succeeds afterwards.
        $this->actingAs($this->admin)->post(route('jotform-import.import', self::CPPT))->assertSessionHas('success');
        $this->assertSame(6, $indicator->formVersions()->sole()->fields()->count());
    }

    public function test_one_failing_form_does_not_stop_the_others_in_a_bulk_import(): void
    {
        $this->mapToNew(self::SBAR);
        $blocked = $this->mapToNew(self::CPPT);
        $this->mapToNew(self::HAND_HYGIENE);

        // A manual draft on one indicator makes its import fail.
        $this->actingAs($this->admin)->post(route('form-builder.draft.store', $blocked));

        $this->actingAs($this->admin)->post(route('jotform-import.import-all'))
            ->assertSessionHas('error', '2 formulir berhasil diimpor, 1 gagal. Lihat keterangan pada tiap formulir.')
            ->assertSessionHas('jotformResults.'.self::CPPT.'.ok', false)
            ->assertSessionHas('jotformResults.'.self::SBAR.'.ok', true);

        $this->assertSame(2, JotformForm::whereNotNull('indicator_form_version_id')->count());
    }

    public function test_unreadable_export_is_reported_without_breaking_the_page(): void
    {
        $this->fakeExport([['type' => 'control_textbox', 'text' => 'Nama']]);
        File::ensureDirectoryExists($this->temporary.'/02-RUSAK');
        File::put($this->temporary.'/02-RUSAK/form.json', '{ rusak');
        File::ensureDirectoryExists($this->temporary.'/03-KEMBAR');
        File::copyDirectory($this->temporary.'/01-UJI', $this->temporary.'/03-KEMBAR');

        $entries = JotformExport::all();

        $this->assertCount(3, $entries);
        $this->assertNotNull($entries[0]['definition']);
        $this->assertStringContainsString('bukan JSON yang valid', $entries[1]['error']);
        $this->assertStringContainsString('sudah ada di folder 01-UJI', $entries[2]['error']);

        $this->actingAs($this->admin)->get(route('jotform-import.index'))
            ->assertOk()
            ->assertSee('Formulir Uji')
            ->assertSee('Berkas tidak dapat dibaca');
    }

    public function test_importer_refuses_an_unmapped_form(): void
    {
        $this->expectException(JotformImportException::class);

        JotformImporter::import($this->definition(self::SBAR), $this->admin);
    }

    // --- page and access ----------------------------------------------------------

    public function test_admin_page_lists_the_14_definitions_with_ids_mapping_and_preview(): void
    {
        $response = $this->actingAs($this->admin)->get(route('jotform-import.index'))->assertOk();

        foreach (JotformExport::all() as $entry) {
            $response->assertSee($entry['definition']->title)->assertSee('Form ID '.$entry['definition']->formId);
        }

        $response
            ->assertSee('Perlu pemetaan')
            ->assertSee('Konfirmasi pemetaan indikator')
            ->assertSee($this->indicators[ObservationForms::SPECIALIST_VISIT]->name)
            ->assertSee('Nama Pemberi Informasi')
            ->assertSee('Dipetakan ke field bawaan')
            ->assertSee('Impor Semua yang Siap (0)')
            ->assertDontSee('987654');

        // The sidebar link is for Admin only.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('jotform-import.index'));
        $this->actingAs(User::factory()->picMutu()->create())->get(route('dashboard'))->assertDontSee(route('jotform-import.index'));
    }

    public static function nonAdminRoles(): array
    {
        return ['pic mutu' => [User::ROLE_PIC_MUTU], 'pic unit' => [User::ROLE_PIC_UNIT]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_admin_can_use_the_jotform_importer(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get(route('jotform-import.index'))->assertForbidden();
        $this->post(route('jotform-import.map', self::SBAR), ['target' => 'new', 'category' => 'imp-rs', 'name' => 'X'])->assertForbidden();
        $this->post(route('jotform-import.import', self::SBAR))->assertForbidden();
        $this->post(route('jotform-import.import-all'))->assertForbidden();

        $this->assertDatabaseCount('jotform_forms', 0);
        $this->assertDatabaseCount('indicators', 4);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('jotform-import.index'))->assertRedirect(route('login'));
        $this->post(route('jotform-import.import-all'))->assertRedirect(route('login'));
    }
}

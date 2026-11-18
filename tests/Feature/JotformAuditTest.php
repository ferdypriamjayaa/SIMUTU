<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\IndicatorFormVersion;
use App\Models\JotformForm;
use App\Services\JotformApi;
use App\Services\JotformApiException;
use App\Support\Jotform\JotformAudit;
use App\Support\Jotform\JotformExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Read-only Jotform tooling: the definition-only API client, the
 * jotform:audit command and jotform:export-definition.
 */
class JotformAuditTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.jotform.test';

    private const KEY = 'kunci-rahasia-jotform';

    /** Registered, imported, draft. */
    private const DRAFT_FORM = '100000000000001';

    /** Registered, imported, published. */
    private const PUBLISHED_FORM = '100000000000002';

    /** Exported, not mapped. */
    private const EXPORTED_FORM = '100000000000003';

    /** In Jotform only (not exported, not registered). */
    private const NEW_FORM = '100000000000004';

    /** In the catalogue, gone from Jotform. */
    private const GONE_FORM = '100000000000005';

    private string $temporary;

    private IndicatorCategory $impRs;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->temporary = storage_path('framework/testing/jotform-audit-'.uniqid());
        File::ensureDirectoryExists($this->temporary);

        config([
            'services.jotform.api_key' => self::KEY,
            'services.jotform.base_url' => self::API,
            'services.jotform.export_path' => $this->temporary,
            'jotform.catalog' => ['IMP_RS' => [
                self::DRAFT_FORM => 'Kepatuhan SBAR',
                self::PUBLISHED_FORM => 'Kepatuhan CPPT',
                self::EXPORTED_FORM => 'Kepatuhan Hand Hygiene',
                self::NEW_FORM => 'Angka Penurunan VAS',
                self::GONE_FORM => 'Formulir Lama',
            ]],
        ]);

        $this->impRs = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'Indikator Mutu Pelayanan Rumah Sakit']);

        $this->exportFolder('01-SBAR', self::DRAFT_FORM, 'Kepatuhan SBAR Dalam Reulasi');
        $this->exportFolder('02-CPPT', self::PUBLISHED_FORM, 'Kepatuhan CPPT');
        $this->exportFolder('03-HAND-HYGIENE', self::EXPORTED_FORM, 'Kepatuhan Hand Hygiene');

        $this->registered(self::DRAFT_FORM, 'Kepatuhan SBAR Dalam Regulasi', IndicatorFormVersion::STATUS_DRAFT);
        $this->registered(self::PUBLISHED_FORM, 'Kepatuhan CPPT', IndicatorFormVersion::STATUS_PUBLISHED);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);

        parent::tearDown();
    }

    private function questions(): array
    {
        return [
            '1' => ['qid' => '1', 'order' => '1', 'type' => 'control_textbox', 'name' => 'namaPetugas', 'text' => 'Nama Petugas'],
            '2' => ['qid' => '2', 'order' => '2', 'type' => 'control_radio', 'name' => 'patuh', 'text' => 'Patuh', 'options' => 'Ya|Tidak'],
        ];
    }

    private function exportFolder(string $directory, string $formId, string $title): void
    {
        File::ensureDirectoryExists("{$this->temporary}/{$directory}");
        File::put("{$this->temporary}/{$directory}/form.json", json_encode(['content' => ['id' => $formId, 'title' => $title, 'status' => 'ENABLED']]));
        File::put("{$this->temporary}/{$directory}/questions.json", json_encode(['content' => $this->questions()]));
    }

    private function registered(string $formId, string $name, string $status): Indicator
    {
        $indicator = Indicator::create(['indicator_category_id' => $this->impRs->id, 'name' => $name, 'is_active' => true]);
        $version = IndicatorFormVersion::create(['indicator_id' => $indicator->id, 'version' => 1, 'status' => $status]);
        $definition = JotformExport::find($formId);

        JotformForm::create([
            'jotform_form_id' => $formId,
            'title' => $definition->title,
            'indicator_id' => $indicator->id,
            'indicator_form_version_id' => $version->id,
            'definition_hash' => $definition->hash,
            'question_count' => 2,
            'imported_at' => '2026-11-11 15:29:20',
        ]);

        return $indicator;
    }

    /** Fake Jotform: form list, one form, its questions; anything else fails the test. */
    private function fakeJotform(array $forms = []): void
    {
        $forms = $forms ?: [
            ['id' => self::DRAFT_FORM, 'title' => 'Kepatuhan SBAR Dalam Reulasi', 'status' => 'ENABLED', 'updated_at' => '2024-10-13 10:52:43', 'count' => '100', 'new' => '12', 'last_submission' => '2026-10-09 00:49:03'],
            ['id' => self::PUBLISHED_FORM, 'title' => 'Kepatuhan CPPT', 'status' => 'ENABLED', 'updated_at' => '2026-11-12 08:00:00'],
            ['id' => self::EXPORTED_FORM, 'title' => 'Kepatuhan Hand Hygiene', 'status' => 'ENABLED', 'updated_at' => '2024-05-14 05:18:59'],
            ['id' => self::NEW_FORM, 'title' => 'Angka Penurunan VAS Di UnitRehabilitasi Medik', 'status' => 'ENABLED', 'updated_at' => '2024-06-06 22:54:23', 'count' => '50'],
            ['id' => '999999999999999', 'title' => 'Absensi Mandiri', 'status' => 'ENABLED'],
        ];

        Http::fake(function (Request $request) use ($forms) {
            if ($request->header('APIKEY') !== [self::KEY] || str_contains($request->url(), self::KEY)) {
                return Http::response(['responseCode' => 401, 'message' => 'Unauthorized'], 401);
            }

            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/user/forms' => Http::response(['responseCode' => 200, 'content' => $forms]),
                $path === '/form/'.self::NEW_FORM => Http::response(['responseCode' => 200, 'content' => collect($forms)->firstWhere('id', self::NEW_FORM)]),
                $path === '/form/'.self::NEW_FORM.'/questions' => Http::response(['responseCode' => 200, 'content' => $this->questions()]),
                default => Http::response(['responseCode' => 404, 'message' => 'Not found'], 404),
            };
        });
    }

    private function snapshot(): array
    {
        return collect(['indicators', 'indicator_form_versions', 'indicator_fields', 'jotform_forms', 'observations'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])
            ->all();
    }

    // --- API client -----------------------------------------------------------------

    public function test_api_client_reads_definitions_only_with_the_key_in_a_header(): void
    {
        $this->fakeJotform();
        $api = app(JotformApi::class);

        $forms = $api->forms();
        $this->assertCount(5, $forms);
        // Submission counters never leave the client.
        $this->assertSame(['id', 'title', 'status', 'updated_at'], array_keys($forms[0]));
        $this->assertArrayNotHasKey('count', $api->form(self::NEW_FORM));
        $this->assertCount(2, $api->questions(self::NEW_FORM));

        Http::assertSent(fn (Request $request) => $request->hasHeader('APIKEY', self::KEY) && ! str_contains($request->url(), self::KEY));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'submission'));
    }

    public function test_submission_endpoints_cannot_be_called(): void
    {
        Http::fake();
        $api = app(JotformApi::class);
        $get = (fn (string $endpoint) => $this->get($endpoint))->bindTo($api, JotformApi::class);

        foreach (['form/'.self::NEW_FORM.'/submissions', 'user/submissions', 'submission/123', 'form/'.self::NEW_FORM.'/reports'] as $endpoint) {
            try {
                $get($endpoint);
                $this->fail("{$endpoint} was allowed.");
            } catch (JotformApiException $exception) {
                $this->assertStringContainsString('tidak diizinkan', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_api_errors_are_readable_and_never_expose_the_key(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);
        Log::spy();

        try {
            app(JotformApi::class)->forms();
            $this->fail('No exception thrown.');
        } catch (JotformApiException $exception) {
            $this->assertStringContainsString('API key Jotform ditolak', $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), self::KEY));

        config(['services.jotform.api_key' => '']);
        $this->expectExceptionMessage('belum dikonfigurasi');
        app(JotformApi::class)->forms();
    }

    // --- audit ------------------------------------------------------------------------

    public function test_audit_matches_by_form_id_and_classifies_every_form(): void
    {
        $this->fakeJotform();
        // An IMP RS indicator without a Jotform source.
        Indicator::create(['indicator_category_id' => $this->impRs->id, 'name' => 'Indikator Manual', 'is_active' => true]);

        $audit = new JotformAudit('IMP_RS', config('jotform.catalog.IMP_RS'), JotformAudit::keyById(app(JotformApi::class)->forms()), JotformExport::all());
        $rows = collect($audit->rows())->keyBy(fn ($row) => $row['form_id'] ?? $row['indicator']);

        $this->assertSame(JotformAudit::DRAFT, $rows[self::DRAFT_FORM]['status']);
        $this->assertSame('Kepatuhan SBAR Dalam Regulasi', $rows[self::DRAFT_FORM]['indicator']);
        // Matched by Form ID although the titles differ; the difference is reported.
        $this->assertStringContainsString('Judul berbeda', implode(' ', $rows[self::DRAFT_FORM]['notes']));

        $this->assertSame(JotformAudit::IMPORTED, $rows[self::PUBLISHED_FORM]['status']);
        $this->assertStringContainsString('diubah di Jotform setelah impor', implode(' ', $rows[self::PUBLISHED_FORM]['notes']));

        $this->assertSame(JotformAudit::UNREGISTERED, $rows[self::EXPORTED_FORM]['status']);
        $this->assertStringContainsString('Ekspor tersedia di 03-HAND-HYGIENE', implode(' ', $rows[self::EXPORTED_FORM]['notes']));

        $this->assertSame(JotformAudit::UNREGISTERED, $rows[self::NEW_FORM]['status']);
        $this->assertSame('Angka Penurunan VAS Di UnitRehabilitasi Medik', $rows[self::NEW_FORM]['jotform_title']);
        $this->assertStringContainsString('belum diekspor', implode(' ', $rows[self::NEW_FORM]['notes']));

        $this->assertSame(JotformAudit::CONFIRM, $rows[self::GONE_FORM]['status']);
        $this->assertSame(JotformAudit::CONFIRM, $rows['Indikator Manual']['status']);
        $this->assertStringContainsString('tanpa sumber Jotform', $rows['Indikator Manual']['notes'][0]);

        // Forms outside the catalogue are not audited.
        $this->assertFalse($rows->has('999999999999999'));

        $this->assertSame([
            'Total formulir IMP RS (daftar referensi)' => 5,
            'Ditemukan di akun Jotform' => 4,
            'Total indikator IMP RS di SIMUTU' => 3,
            'Sudah terdaftar' => 2,
            'Belum terdaftar' => 2,
            'Sudah diimpor' => 2,
            'Masih draft' => 1,
            'Perlu konfirmasi' => 2,
        ], $audit->summary($audit->rows()));
    }

    public function test_audit_command_prints_the_report_and_changes_nothing(): void
    {
        $this->fakeJotform();
        $before = $this->snapshot();

        $this->artisan('jotform:audit')
            ->expectsOutputToContain('Daftar formulir Jotform: 5 formulir di akun.')
            ->expectsOutputToContain('Belum Terdaftar')
            ->expectsOutputToContain('Belum masuk SIMUTU:')
            ->expectsOutputToContain('Angka Penurunan VAS Di UnitRehabilitasi Medik ('.self::NEW_FORM.')')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'submission'));
    }

    public function test_audit_runs_offline_and_when_jotform_fails(): void
    {
        Http::fake();
        $this->artisan('jotform:audit', ['--offline' => true])
            ->expectsOutputToContain('tidak diperiksa')
            ->assertSuccessful();
        Http::assertNothingSent();

        Http::fake(['*' => Http::response([], 500)]);
        $this->artisan('jotform:audit')
            ->expectsOutputToContain('Audit dilanjutkan tanpa data Jotform')
            ->assertSuccessful();

        $this->artisan('jotform:audit', ['--category' => 'TIDAK_ADA'])->assertFailed();
    }

    // --- export -----------------------------------------------------------------------

    public function test_export_definition_writes_a_new_folder_without_submission_data(): void
    {
        $this->fakeJotform();
        $before = $this->snapshot();

        $this->artisan('jotform:export-definition', ['form' => self::NEW_FORM])
            ->expectsOutputToContain('disimpan di JOTFORM-EXPORT/04-ANGKA-PENURUNAN-VAS-DI-UNITREHABILITASI')
            ->expectsOutputToContain('Belum ada yang diimpor')
            ->assertSuccessful();

        $directory = $this->temporary.'/04-ANGKA-PENURUNAN-VAS-DI-UNITREHABILITASI';
        $form = json_decode(File::get($directory.'/form.json'), true)['content'];
        $this->assertSame(['id' => self::NEW_FORM, 'title' => 'Angka Penurunan VAS Di UnitRehabilitasi Medik', 'status' => 'ENABLED', 'updated_at' => '2024-06-06 22:54:23'], $form);

        // The import page reads it like any other export.
        $definition = JotformExport::find(self::NEW_FORM);
        $this->assertTrue($definition->isImportable());
        $this->assertCount(2, $definition->fields);

        // Nothing imported or registered; only definition endpoints were called.
        $this->assertSame($before, $this->snapshot());
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'submission'));
    }

    public function test_export_never_overwrites_or_duplicates(): void
    {
        $this->fakeJotform();

        // Already exported: refused before Jotform is asked.
        $this->artisan('jotform:export-definition', ['form' => self::EXPORTED_FORM])
            ->expectsOutputToContain('sudah diekspor di folder 03-HAND-HYGIENE')
            ->assertFailed();
        Http::assertNothingSent();

        $this->artisan('jotform:export-definition', ['form' => self::NEW_FORM, '--directory' => '01-SBAR'])
            ->expectsOutputToContain('sudah ada')
            ->assertFailed();
        $this->assertSame(self::DRAFT_FORM, JotformExport::find(self::DRAFT_FORM)->formId);

        $this->artisan('jotform:export-definition', ['form' => self::NEW_FORM, '--directory' => '../luar'])->assertFailed();
        $this->artisan('jotform:export-definition', ['form' => 'abc'])->assertFailed();

        // Unknown form: nothing written.
        $this->artisan('jotform:export-definition', ['form' => '123456789012345'])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertFailed();
        $this->assertCount(3, JotformExport::all());
    }
}

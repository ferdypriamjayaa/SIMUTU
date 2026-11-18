<?php

namespace Tests\Feature;

use App\Models\HrisUnitMapping;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Services\DokterException;
use App\Services\DokterService;
use App\Support\DoctorPicker;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * Doctor API integration, against a faked API (docs/API-Dokter.md).
 */
class DokterApiTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private const URL = 'https://dokter.test/api/dokter';

    /** @var array<string, Indicator> */
    private array $inm;

    private Unit $igd;

    private User $admin;

    private User $picUnit;

    /** Status the faked API answers with; anything but 200 is an error. */
    private int $apiStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.dokter.url' => self::URL,
            'services.dokter.username' => 'simutu',
            'services.dokter.password' => 'rahasia-dokter',
        ]);

        $this->inm = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->igd = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->assignIndicators($this->igd, $this->inm);
        $this->picUnit = User::factory()->picUnit($this->igd)->create();
    }

    /** Fake the Doctor API: Basic auth exactly as documented. */
    private function fakeApi(): void
    {
        Http::fake([self::URL => function (Request $request) {
            if ($this->apiStatus !== 200) {
                return Http::response(['message' => 'x'], $this->apiStatus);
            }

            if (! $request->hasHeader('Authorization', 'Basic '.base64_encode('simutu:rahasia-dokter'))) {
                return Http::response(['message' => 'Unauthorized.'], 401);
            }

            return Http::response(['data' => [
                ['nama' => 'dr. Andi Contoh, Sp.PD', 'spesialis' => 'Penyakit Dalam', 'rumah_sakit' => 'RSU Syifa Medika Banjarbaru'],
                ['nama' => 'dr. Budi Barabai, Sp.A', 'spesialis' => 'Spesialis Anak', 'rumah_sakit' => 'RSU Syifa Medika Barabai'],
                ['nama' => 'drg. Citra', 'spesialis' => null, 'rumah_sakit' => 'RSU Syifa Medika Banjarbaru'],
                ['nama' => '  ', 'spesialis' => 'Kosong', 'rumah_sakit' => 'RSU Syifa Medika Banjarbaru'],
            ]]);
        }]);
    }

    private function service(): DokterService
    {
        return app(DokterService::class);
    }

    private function doctorId(string $name): string
    {
        return collect($this->service()->doctors())->firstWhere('name', $name)['id'];
    }

    private function visitInput(array $overrides = []): array
    {
        return [
            'indicator_id' => $this->inm[ObservationForms::SPECIALIST_VISIT]->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['visite dokter spesialis'][1],
            ...$overrides,
        ];
    }

    // --- service --------------------------------------------------------------------

    public function test_authenticates_with_basic_auth_and_keeps_only_documented_fields(): void
    {
        $this->fakeApi();

        $doctors = $this->service()->doctors();

        $this->assertSame(['dr. Andi Contoh, Sp.PD', 'dr. Budi Barabai, Sp.A', 'drg. Citra'], array_column($doctors, 'name'));
        $this->assertSame(['id', 'name', 'specialty', 'hospital'], array_keys($doctors[0]));
        $this->assertSame(['Penyakit Dalam', 'RSU Syifa Medika Banjarbaru'], [$doctors[0]['specialty'], $doctors[0]['hospital']]);
        $this->assertNull($doctors[2]['specialty']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $doctors[0]['id']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('simutu:rahasia-dokter')));
    }

    public function test_list_is_cached_and_can_be_refreshed(): void
    {
        $this->fakeApi();

        $id = $this->service()->doctors()[0]['id'];
        $this->service()->doctors();
        $this->service()->summary();
        Http::assertSentCount(1);

        // The identifier is stable across fetches.
        $this->assertSame($id, $this->service()->doctors(refresh: true)[0]['id']);
        Http::assertSentCount(2);
    }

    public static function failures(): array
    {
        return [
            'wrong credentials' => [401, 'Kredensial API Dokter ditolak'],
            'wrong url' => [404, 'Alamat API Dokter tidak ditemukan'],
            'rate limited' => [429, 'membatasi jumlah permintaan'],
            'server error' => [500, 'API Dokter sedang mengalami gangguan'],
        ];
    }

    #[DataProvider('failures')]
    public function test_http_errors_become_readable_messages_without_credentials(int $status, string $message): void
    {
        Http::fake([self::URL => Http::response(['message' => 'x'], $status)]);
        Log::spy();

        try {
            $this->service()->doctors();
            $this->fail('Expected DokterException.');
        } catch (DokterException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString('rahasia-dokter', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $text, array $context) => ! str_contains(json_encode($context), 'rahasia-dokter')
            && ! str_contains(json_encode($context), 'simutu')
            && $context['status'] === $status);
    }

    public function test_connection_failures_are_retried_then_reported(): void
    {
        $attempts = 0;
        Http::fake([self::URL => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        }]);

        try {
            $this->service()->doctors();
            $this->fail('No exception thrown.');
        } catch (DokterException $exception) {
            $this->assertStringContainsString('Server API Dokter tidak dapat dihubungi', $exception->getMessage());
        }

        // One attempt plus one retry, so a form never waits long on a dead server.
        $this->assertSame(2, $attempts);
    }

    public function test_malformed_answers_are_rejected(): void
    {
        Http::fake([self::URL => Http::response('<html>bukan json</html>', 200)]);

        $this->expectException(DokterException::class);
        $this->expectExceptionMessage('tidak dapat dibaca');

        $this->service()->doctors();
    }

    public function test_last_good_list_is_used_while_the_api_is_down(): void
    {
        $this->fakeApi();
        $this->service()->doctors();

        $this->apiStatus = 503;

        $this->assertCount(3, $this->service()->doctors(refresh: true));

        // An explicit reload still reports the failure.
        $this->expectException(DokterException::class);
        $this->service()->refresh();
    }

    public function test_not_configured_never_calls_the_api(): void
    {
        config(['services.dokter.password' => '']);
        Http::fake();

        $this->assertFalse($this->service()->isConfigured());
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::SPECIALIST_VISIT]))
            ->assertOk()
            ->assertSee('type="text" name="doctor_name"', false)
            ->assertDontSee('__doctor');

        Http::assertNothingSent();
    }

    // --- observation form ---------------------------------------------------------------

    public function test_doctor_field_offers_active_doctors_grouped_by_hospital(): void
    {
        $this->fakeApi();
        // The unit belongs to Barabai (HRIS company 0002): its doctors come first.
        HrisUnitMapping::create(['unit_id' => $this->igd->id, 'company_code' => '0002', 'division' => 'Divisi', 'department' => 'Dept']);

        // A searchable dropdown; the doctor list itself is not on the page.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::SPECIALIST_VISIT]))
            ->assertOk()
            ->assertSee('name="doctor_name__doctor"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('Lainnya — ketik manual')
            ->assertDontSee('dr. Andi Contoh')
            ->assertDontSee('rahasia-dokter')
            ->assertDontSee(self::URL);

        // The search lists them grouped by hospital, the unit's own first.
        $json = $this->actingAs($this->picUnit)->getJson(route('observations.lookup', 'doctors'))
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.*.group', ['RSU Syifa Medika Barabai', 'RSU Syifa Medika Banjarbaru', 'RSU Syifa Medika Banjarbaru'])
            ->assertJsonPath('data.1', [
                'value' => $this->doctorId('dr. Andi Contoh, Sp.PD'),
                'label' => 'dr. Andi Contoh, Sp.PD',
                'description' => 'Penyakit Dalam',
                'group' => 'RSU Syifa Medika Banjarbaru',
            ])
            ->getContent();
        $this->assertStringNotContainsString('rahasia-dokter', $json);
        $this->assertStringContainsString($this->doctorId('drg. Citra'), $json);

        // By name or specialty, every word, ignoring case.
        $search = fn (string $q) => $this->actingAs($this->picUnit)->getJson(route('observations.lookup', ['doctors', 'q' => $q]));
        $search('citra')->assertJsonPath('data.*.label', ['drg. Citra']);
        $search('anak')->assertJsonPath('data.*.label', ['dr. Budi Barabai, Sp.A']);
        $search('PENYAKIT andi')->assertJsonPath('data.*.label', ['dr. Andi Contoh, Sp.PD']);
        $search('tidak ada')->assertOk()->assertJsonPath('data', [])->assertJsonPath('next_page', null);

        // PIC Mutu does not fill in forms, so does not search either.
        $this->actingAs(User::factory()->picMutu()->create())->getJson(route('observations.lookup', 'doctors'))->assertForbidden();

        // Not configured: the reason, so the user can type the name instead.
        config(['services.dokter.password' => '']);
        $this->actingAs($this->picUnit)->getJson(route('observations.lookup', 'doctors'))
            ->assertStatus(503)->assertJsonPath('message', 'Integrasi API Dokter belum dikonfigurasi.');
        config(['services.dokter.password' => 'rahasia-dokter']);

        // Other INM forms do not get a doctor dropdown.
        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::PPE]))
            ->assertOk()
            ->assertDontSee('__doctor');
    }

    public function test_chosen_doctor_is_stored_as_the_doctors_name(): void
    {
        $this->fakeApi();

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput([
            'doctor_name' => 'ditimpa',
            'doctor_name__doctor' => $this->doctorId('dr. Budi Barabai, Sp.A'),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('observations.create'));

        $observation = Observation::sole();
        $this->assertSame('dr. Budi Barabai, Sp.A', $observation->data['doctor_name']);
        $this->assertSame('dr. Budi Barabai, Sp.A', $observation->values()->whereHas('field', fn ($q) => $q->where('key', 'doctor_name'))->value('value'));

        // The edit page preselects that doctor.
        $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))->assertOk()
            ->assertSee('name="doctor_name__doctor" value="'.$this->doctorId('dr. Budi Barabai, Sp.A').'"', false)
            ->assertSee('dr. Budi Barabai, Sp.A');
    }

    public function test_manual_entry_and_unknown_doctors(): void
    {
        $this->fakeApi();

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput([
            'doctor_name' => 'dr. Tamu, Sp.B', 'doctor_name__doctor' => DoctorPicker::MANUAL,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('dr. Tamu, Sp.B', Observation::sole()->data['doctor_name']);

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput(['doctor_name__doctor' => 'palsu']))
            ->assertSessionHasErrors(['doctor_name' => 'Dokter yang dipilih tidak ditemukan pada daftar dokter aktif. Pilih ulang dari daftar.']);

        $this->assertSame(1, Observation::count());
    }

    public function test_form_falls_back_to_typing_when_the_api_is_unavailable(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'x'], 503)]);

        $this->actingAs($this->picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::SPECIALIST_VISIT]))
            ->assertOk()
            ->assertSee('type="text" name="doctor_name"', false)
            ->assertDontSee('__doctor')
            ->assertSee('API Dokter sedang mengalami gangguan');

        // Saving a typed name still works.
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput())->assertSessionHasNoErrors();
        $this->assertSame('dr. Contoh, Sp.PD', Observation::sole()->data['doctor_name']);

        // A dropdown choice submitted while the API is down is refused with a way out.
        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput(['doctor_name__doctor' => 'abc']))
            ->assertSessionHasErrors('doctor_name');
    }

    public function test_edit_keeps_stored_names_outside_the_list_unchanged(): void
    {
        $this->fakeApi();

        $this->actingAs($this->picUnit)->post(route('observations.store'), $this->visitInput(['doctor_name' => 'dr. Lama Tidak Di API']))
            ->assertSessionHasNoErrors();
        $observation = Observation::sole();

        $html = $this->actingAs($this->picUnit)->get(route('observations.edit', $observation))->assertOk()->getContent();
        $this->assertStringContainsString('name="doctor_name__doctor" value="__manual"', $html);
        $this->assertStringContainsString('value="dr. Lama Tidak Di API"', $html);
        $this->assertSame('dr. Lama Tidak Di API', $observation->fresh()->data['doctor_name']);
    }

    // --- mapping page -------------------------------------------------------------------

    public function test_mapping_page_shows_doctor_api_status_and_refreshes(): void
    {
        $this->fakeApi();

        $this->actingAs($this->admin)->get(route('hris-mappings.index'))
            ->assertOk()
            ->assertSee('Daftar Dokter — API Dokter')
            ->assertSee('RSU Syifa Medika Banjarbaru')
            ->assertSee('2 dokter aktif')
            ->assertSee('1 dokter aktif')
            ->assertDontSee('rahasia-dokter');

        $this->actingAs($this->admin)->post(route('hris-mappings.refresh-doctors'))
            ->assertRedirect(route('hris-mappings.index'))
            ->assertSessionHas('success', 'Data dokter berhasil dimuat ulang: 3 dokter aktif.');

        $this->apiStatus = 401;
        $this->actingAs($this->admin)->post(route('hris-mappings.refresh-doctors'))->assertSessionHas('error');

        config(['services.dokter.url' => '']);
        $this->actingAs($this->admin)->get(route('hris-mappings.index'))->assertOk()->assertSee('API Dokter belum dikonfigurasi');
    }

    public function test_only_admin_refreshes_doctors(): void
    {
        Http::fake();

        $this->actingAs($this->picUnit)->post(route('hris-mappings.refresh-doctors'))->assertForbidden();
        Http::assertNothingSent();
    }
}

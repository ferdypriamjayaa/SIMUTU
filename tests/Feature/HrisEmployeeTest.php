<?php

namespace Tests\Feature;

use App\Models\HrisUnitMapping;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\HrisException;
use App\Support\EmployeePicker;
use App\Support\ObservationForms;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * HRIS employee integration, against a faked API (docs/api-karyawan-rs.md).
 */
class HrisEmployeeTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private const URL = 'https://hris.test';

    private const NURSING = ['Divisi Pelayanan Medis', 'Departemen Keperawatan'];

    private const LAB = ['Divisi Penunjang Medis', 'Departemen Laboratorium'];

    /** @var array<string, Indicator> */
    private array $inm;

    private Unit $igd;

    private Unit $lab;

    private User $admin;

    /** @var array<string, list<array<string, mixed>>> employees per company code */
    private array $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.hris.url' => self::URL,
            'services.hris.username' => 'simutu',
            'services.hris.password' => 'rahasia',
            'services.hris.company_codes' => ['0001', '0002'],
            'services.hris.per_page' => 2,
        ]);

        $this->directory = [
            '0001' => [
                $this->person('19870100001', 'Ahmad Fauzi', ...self::NURSING),
                $this->person('19870100002', 'Siti Hadiyanti', ...self::NURSING),
                $this->person('19870100003', 'Budi Santoso', ...self::LAB),
                $this->person(null, 'Rina Tanpa NIP', ...self::NURSING),
                $this->person('19870100005', 'Belum Ditempatkan', null, null),
            ],
            '0002' => [
                $this->person('19900200001', 'Dewi Banjarbaru', ...self::NURSING),
            ],
        ];

        $this->inm = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->igd = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->lab = Unit::create(['code' => 'LAB', 'name' => 'Laboratorium', 'is_active' => true]);
        $this->assignIndicators($this->igd, $this->inm);
        $this->assignIndicators($this->lab, $this->inm);
    }

    private function person(?string $nip, string $name, ?string $division, ?string $department): array
    {
        return ['nip' => $nip, 'nama' => $name, 'divisi' => $division, 'departemen' => $department, 'perusahaan' => 'PT Contoh Perusahaan HRIS'];
    }

    /** Fake the HRIS API: Basic auth, kode, paging exactly as documented. */
    private function fakeHris(): void
    {
        Http::fake([self::URL.'/api/karyawan-rs*' => function (Request $request) {
            if (! $request->hasHeader('Authorization', 'Basic '.base64_encode('simutu:rahasia'))) {
                return Http::response(['success' => false, 'message' => 'Unauthorized.', 'errors' => null], 401);
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if (! isset($this->directory[$query['kode'] ?? ''])) {
                return Http::response(['success' => false, 'message' => 'Not found.', 'errors' => null], 404);
            }

            // Documented filters: nama contains (case-insensitive), nip exact.
            $all = array_values(array_filter($this->directory[$query['kode']], fn (array $row) => (! isset($query['nama']) || str_contains(mb_strtolower($row['nama']), mb_strtolower($query['nama'])))
                && (! isset($query['nip']) || $row['nip'] === $query['nip'])));
            $perPage = (int) ($query['per_page'] ?? 50);
            $page = (int) ($query['page'] ?? 1);
            $rows = array_slice($all, ($page - 1) * $perPage, $perPage);

            return Http::response([
                'success' => true,
                'message' => 'Daftar karyawan rumah sakit.',
                'data' => $rows,
                'pagination' => ['total' => count($all), 'count' => count($rows), 'per_page' => $perPage, 'current_page' => $page],
            ]);
        }]);
    }

    private function map(Unit $unit, array $placement, string $company = '0001'): HrisUnitMapping
    {
        return HrisUnitMapping::create(['unit_id' => $unit->id, 'company_code' => $company, 'division' => $placement[0], 'department' => $placement[1]]);
    }

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    // --- service --------------------------------------------------------------------

    public function test_authenticates_with_basic_auth_and_reads_every_page(): void
    {
        $this->fakeHris();

        $employees = $this->service()->employees('0001');

        $this->assertSame(['Ahmad Fauzi', 'Belum Ditempatkan', 'Budi Santoso', 'Rina Tanpa NIP', 'Siti Hadiyanti'], array_column($employees, 'name'));

        // 5 employees at 2 per page = 3 requests, each authenticated, from the backend.
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::URL.'/api/karyawan-rs?')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('simutu:rahasia'))
            && $request['kode'] === '0001' && (int) $request['per_page'] === 2);
        foreach ([1, 2, 3] as $page) {
            Http::assertSent(fn (Request $request) => (int) $request['page'] === $page);
        }
    }

    public function test_only_needed_fields_are_kept_and_the_nip_never_leaves_the_service(): void
    {
        $this->fakeHris();

        $employees = $this->service()->employees('0001');
        $ahmad = $employees[0];

        $this->assertSame(['id', 'name', 'division', 'department'], array_keys($ahmad));
        $this->assertSame(['Ahmad Fauzi', 'Divisi Pelayanan Medis', 'Departemen Keperawatan'], [$ahmad['name'], $ahmad['division'], $ahmad['department']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ahmad['id']);

        $everything = json_encode([$employees, Cache::get(collect(array_keys((fn () => $this->storage)->call(Cache::store()->getStore())))->first())]);
        $this->assertStringNotContainsString('19870100001', $everything);
        $this->assertStringNotContainsString('perusahaan', json_encode($employees));

        // The identifier is stable, and differs per person (also without a NIP).
        $this->assertSame($ahmad['id'], $this->service()->employees('0001', refresh: true)[0]['id']);
        $this->assertCount(5, array_unique(array_column($employees, 'id')));
    }

    public function test_lists_are_cached_and_can_be_refreshed(): void
    {
        $this->fakeHris();

        $this->service()->employees('0001');
        $this->service()->employees('0001');
        $this->service()->placements('0001');
        Http::assertSentCount(3);

        $this->service()->employees('0001', refresh: true);
        Http::assertSentCount(6);

        // Each company has its own list.
        $this->assertSame(['Dewi Banjarbaru'], array_column($this->service()->employees('0002'), 'name'));
    }

    public static function failures(): array
    {
        return [
            'wrong credentials' => [401, 'Kredensial API HRIS ditolak'],
            'unknown company' => [404, 'Kode perusahaan tidak dikenali'],
            'invalid request' => [422, 'Permintaan ke HRIS tidak valid'],
            'rate limited' => [429, 'membatasi jumlah permintaan'],
            'server error' => [500, 'HRIS sedang mengalami gangguan'],
        ];
    }

    #[DataProvider('failures')]
    public function test_http_errors_become_readable_messages(int $status, string $message): void
    {
        Http::fake([self::URL.'/*' => Http::response(['success' => false, 'message' => 'x', 'errors' => null], $status)]);

        try {
            $this->service()->employees('0001');
            $this->fail('No exception thrown.');
        } catch (HrisException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString('rahasia', $exception->getMessage());
        }

        // 4xx/5xx answers are not retried.
        Http::assertSentCount(1);
    }

    public function test_connection_failures_are_retried_then_reported(): void
    {
        $attempts = 0;
        Http::fake([self::URL.'/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        }]);

        try {
            $this->service()->employees('0001');
            $this->fail('No exception thrown.');
        } catch (HrisException $exception) {
            $this->assertStringContainsString('Server HRIS tidak dapat dihubungi', $exception->getMessage());
        }

        // One attempt plus one retry, so a form never waits long on a dead server.
        $this->assertSame(2, $attempts);
    }

    public function test_malformed_answers_are_rejected(): void
    {
        Http::fake([self::URL.'/*' => Http::response('<html>maintenance</html>', 200)]);

        $this->expectException(HrisException::class);
        $this->expectExceptionMessage('tidak dapat dibaca');

        $this->service()->employees('0001');
    }

    public function test_last_good_list_is_used_while_hris_is_down(): void
    {
        $this->fakeHris();
        $this->service()->employees('0001');

        Http::fake([self::URL.'/*' => Http::response(['success' => false, 'message' => 'x', 'errors' => null], 500)]);
        $this->service()->forget('0001');

        $this->assertCount(5, $this->service()->employees('0001'));
    }

    public function test_not_configured_or_unlisted_company_never_calls_hris(): void
    {
        Http::fake();

        config(['services.hris.url' => '']);
        $this->assertFalse($this->service()->isConfigured());
        $this->assertThrows(fn () => $this->service()->employees('0001'), HrisException::class, 'belum dikonfigurasi');

        config(['services.hris.url' => self::URL]);
        $this->assertThrows(fn () => $this->service()->employees('0003'), HrisException::class, 'tidak termasuk');

        Http::assertNothingSent();
    }

    public function test_employees_for_a_unit_follow_its_mappings(): void
    {
        $this->fakeHris();

        $this->assertSame([], $this->service()->forUnit($this->igd));
        Http::assertNothingSent();

        $this->map($this->igd, self::NURSING);
        $this->map($this->igd, self::NURSING, '0002');
        $this->map($this->lab, self::LAB);

        $this->assertSame(['Ahmad Fauzi', 'Dewi Banjarbaru', 'Rina Tanpa NIP', 'Siti Hadiyanti'], array_column($this->service()->forUnit($this->igd), 'name'));
        $this->assertSame(['Budi Santoso'], array_column($this->service()->forUnit($this->lab), 'name'));
    }

    // --- admin mapping page -----------------------------------------------------------

    public function test_placement_shows_the_unit_name_as_title_and_its_type_as_subtitle(): void
    {
        $this->fakeHris();

        // HRIS divisi = the unit's name, departemen = its type.
        $this->directory['0001'] = [$this->person('19870100001', 'Ahmad Fauzi', 'Unit Perawatan LT. 3 Gedung Marwah', 'Pelayanan Medik')];

        $this->actingAs($this->admin)->get(route('hris-mappings.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<p class="text-base font-bold leading-snug text-navy-900 dark:text-slate-100">Unit Perawatan LT. 3 Gedung Marwah</p>',
                '<p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">Pelayanan Medik</p>',
                '1 karyawan aktif',
            ], false);
    }

    public function test_mapping_in_place_answers_json_for_the_page_to_update(): void
    {
        $this->fakeHris();
        $placement = ['company_code' => '0001', 'division' => self::NURSING[0], 'department' => self::NURSING[1]];

        $this->actingAs($this->admin)->postJson(route('hris-mappings.store'), [...$placement, 'unit_id' => $this->igd->id])
            ->assertOk()
            ->assertJson([
                'message' => '"'.self::NURSING[0].'" dipetakan ke unit Instalasi Gawat Darurat.',
                'created' => true,
                'mapped_unit_ids' => [$this->igd->id],
                'summary' => '3 departemen/divisi · 1 sudah dipetakan',
                'unmapped_count' => 1,
            ])
            ->assertJsonPath('mapped_html', fn (string $html) => str_contains($html, 'Instalasi Gawat Darurat')
                && str_contains($html, route('hris-mappings.destroy', HrisUnitMapping::sole())))
            ->assertJsonPath('unmapped_html', fn (string $html) => str_contains($html, 'Laboratorium'));

        // A second unit for the same placement: both chips come back.
        $this->actingAs($this->admin)->postJson(route('hris-mappings.store'), [...$placement, 'unit_id' => $this->lab->id])
            ->assertOk()
            ->assertJsonPath('mapped_unit_ids', [$this->igd->id, $this->lab->id])
            ->assertJsonPath('unmapped_count', 0);

        // Refusals are 422 with a readable message, and nothing is stored.
        $this->actingAs($this->admin)->postJson(route('hris-mappings.store'), ['company_code' => '0001', 'division' => 'Karangan', 'department' => null, 'unit_id' => $this->igd->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Departemen tersebut tidak ditemukan di HRIS. Muat ulang data HRIS lalu coba lagi.']);
        $this->actingAs($this->admin)->postJson(route('hris-mappings.store'), [...$placement, 'unit_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_id' => 'Pilih unit SIMUTU.']);

        $this->assertSame(2, HrisUnitMapping::count());
    }

    public function test_admin_maps_hris_departments_to_units_manually(): void
    {
        $this->fakeHris();

        $this->actingAs($this->admin)->get(route('hris-mappings.index'))
            ->assertOk()
            ->assertSee('Departemen Keperawatan')
            ->assertSee('Departemen Laboratorium')
            ->assertSee('3 karyawan aktif')
            ->assertSee('Belum ditempatkan di HRIS')
            ->assertSee('RS BJB')
            ->assertSee('RS BRB')
            ->assertDontSee('19870100001')
            ->assertDontSee('rahasia');

        // Nothing is mapped automatically, not even an exact name match.
        $this->assertDatabaseCount('hris_unit_mappings', 0);

        $this->actingAs($this->admin)->post(route('hris-mappings.store'), [
            'company_code' => '0001', 'division' => self::NURSING[0], 'department' => self::NURSING[1], 'unit_id' => $this->igd->id,
        ])->assertSessionHas('success')->assertRedirect(route('hris-mappings.index', ['company' => '0001']));

        // The same pair again is not stored twice.
        $this->actingAs($this->admin)->post(route('hris-mappings.store'), [
            'company_code' => '0001', 'division' => self::NURSING[0], 'department' => self::NURSING[1], 'unit_id' => $this->igd->id,
        ])->assertSessionHas('success');
        $this->assertSame(1, HrisUnitMapping::count());

        $mapping = HrisUnitMapping::sole();
        $this->assertSame([$this->igd->id, '0001', ...self::NURSING], [$mapping->unit_id, $mapping->company_code, $mapping->division, $mapping->department]);

        $this->actingAs($this->admin)->get(route('hris-mappings.index'))->assertSee('Instalasi Gawat Darurat');

        $this->actingAs($this->admin)->delete(route('hris-mappings.destroy', $mapping))->assertSessionHas('success');
        $this->assertDatabaseCount('hris_unit_mappings', 0);
    }

    public function test_only_placements_reported_by_hris_can_be_mapped(): void
    {
        $this->fakeHris();
        $store = fn (array $data) => $this->actingAs($this->admin)->post(route('hris-mappings.store'), [
            'company_code' => '0001', 'unit_id' => $this->igd->id, ...$data,
        ]);

        $store(['division' => 'Divisi Karangan', 'department' => 'Departemen Karangan'])->assertSessionHas('error');
        $store(['division' => null, 'department' => null])->assertSessionHas('error');
        $store(['division' => self::NURSING[0], 'department' => self::NURSING[1], 'company_code' => '0009'])->assertSessionHasErrors('company_code');
        $store(['division' => self::NURSING[0], 'department' => self::NURSING[1], 'unit_id' => 999999])->assertSessionHasErrors('unit_id');

        $inactive = Unit::create(['code' => 'OLD', 'name' => 'Unit Lama', 'is_active' => false]);
        $store(['division' => self::NURSING[0], 'department' => self::NURSING[1], 'unit_id' => $inactive->id])->assertSessionHasErrors('unit_id');

        $this->assertDatabaseCount('hris_unit_mappings', 0);

        // The database refuses a duplicate pair, NULLs included.
        $this->map($this->igd, ['Divisi Pelayanan Medis', null]);
        $this->expectException(QueryException::class);
        $this->map($this->igd, ['Divisi Pelayanan Medis', null]);
    }

    public function test_mapping_page_reports_hris_problems_and_keeps_saved_mappings(): void
    {
        $this->map($this->igd, ['Divisi Lama', 'Departemen Lama']);
        Http::fake([self::URL.'/*' => Http::response(['success' => false, 'message' => 'Unauthorized.', 'errors' => null], 401)]);

        $this->actingAs($this->admin)->get(route('hris-mappings.index'))
            ->assertOk()
            ->assertSee('Data HRIS tidak dapat dimuat')
            ->assertSee('Kredensial API HRIS ditolak')
            ->assertSee('Departemen Lama');

        $this->actingAs($this->admin)->post(route('hris-mappings.refresh'), ['company_code' => '0001'])->assertSessionHas('error');

        config(['services.hris.url' => '']);
        $this->actingAs($this->admin)->get(route('hris-mappings.index'))->assertOk()->assertSee('belum dikonfigurasi');
    }

    public static function nonAdminRoles(): array
    {
        return ['pic mutu' => [User::ROLE_PIC_MUTU], 'pic unit' => [User::ROLE_PIC_UNIT]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_admin_manages_hris_mappings(string $role): void
    {
        Http::fake();
        $mapping = $this->map($this->igd, self::NURSING);
        $this->actingAs(User::factory()->create(['role' => $role, 'unit_id' => $this->igd->id]));

        $this->get(route('dashboard'))->assertDontSee(route('hris-mappings.index'));
        $this->get(route('hris-mappings.index'))->assertForbidden();
        $this->post(route('hris-mappings.store'), ['company_code' => '0001', 'department' => 'X', 'unit_id' => $this->igd->id])->assertForbidden();
        $this->post(route('hris-mappings.refresh'), ['company_code' => '0001'])->assertForbidden();
        $this->delete(route('hris-mappings.destroy', $mapping))->assertForbidden();

        $this->assertModelExists($mapping);
        Http::assertNothingSent();
    }

    // --- observation form ------------------------------------------------------------

    private function ppeInput(array $overrides = []): array
    {
        return [
            'indicator_id' => $this->inm[ObservationForms::PPE]->id,
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['apd'][1],
            ...$overrides,
        ];
    }

    private function employeeId(string $name): string
    {
        return collect($this->service()->employees('0001'))->firstWhere('name', $name)['id'];
    }

    public function test_officer_dropdown_lists_only_the_units_employees(): void
    {
        $this->fakeHris();
        $this->map($this->igd, self::NURSING);
        $picUnit = User::factory()->picUnit($this->igd)->create();

        // The form has a searchable dropdown; the employee list itself is not on the page.
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::PPE]))
            ->assertOk()
            ->assertSee('name="officer_name__employee"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee(str_replace('/', '\\\\\\/', route('observations.lookup', 'employees')), false)
            ->assertSee('Lainnya — ketik manual')
            ->assertDontSee('Ahmad Fauzi')
            ->assertDontSee('Siti Hadiyanti');

        // The search returns the unit's employees, names only: no division, department, company or NIP.
        $json = $this->actingAs($picUnit)->getJson(route('observations.lookup', 'employees'))
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.0', ['value' => $this->employeeId('Ahmad Fauzi'), 'label' => 'Ahmad Fauzi'])
            ->getContent();
        $this->assertSame(['Ahmad Fauzi', 'Rina Tanpa NIP', 'Siti Hadiyanti'], array_column(json_decode($json, true)['data'], 'label'));
        foreach (['Departemen Keperawatan', 'Divisi Pelayanan Medis', 'PT Contoh Perusahaan HRIS', '19870100001', 'Budi Santoso'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }

        // The visit form's doctor field stays a text input.
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->inm[ObservationForms::SPECIALIST_VISIT]))
            ->assertOk()
            ->assertDontSee('__employee');

        // Another unit with another department gets its own staff.
        $this->map($this->lab, self::LAB);
        $this->actingAs(User::factory()->picUnit($this->lab)->create())
            ->getJson(route('observations.lookup', 'employees'))
            ->assertOk()
            ->assertJsonPath('data.*.label', ['Budi Santoso']);
    }

    public function test_employee_search_filters_pages_and_checks_access(): void
    {
        $this->fakeHris();
        $this->map($this->igd, self::NURSING);
        $picUnit = User::factory()->picUnit($this->igd)->create();
        // Guests are not let in.
        $this->getJson(route('observations.lookup', 'employees'))->assertUnauthorized();

        $search = fn (array $query) => $this->actingAs($picUnit)->getJson(route('observations.lookup', ['employees', ...$query]));

        // Every word must match, ignoring case.
        $search(['q' => 'siti'])->assertOk()->assertJsonPath('data.*.label', ['Siti Hadiyanti']);
        $search(['q' => 'HADI siti'])->assertJsonPath('data.*.label', ['Siti Hadiyanti']);
        $search(['q' => 'budi'])->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0); // another unit's staff
        $search(['q' => str_repeat('a', 101)])->assertUnprocessable();

        // One page at a time.
        $this->assertSame(['data' => [['value' => 'b', 'label' => 'B']], 'page' => 2, 'next_page' => 3, 'total' => 5],
            \App\Support\PickerSearch::page(array_map(fn ($l) => ['value' => strtolower($l), 'label' => $l], ['A', 'B', 'C', 'D', 'E']), '', 2, fn ($i) => $i['label'], 1));

        // Unmapped unit: empty list without calling HRIS.
        $lab = User::factory()->picUnit($this->lab)->create();
        $this->actingAs($lab)->getJson(route('observations.lookup', 'employees'))->assertOk()->assertJsonPath('total', 0);

        // Only those who may fill in the form can search.
        $this->actingAs(User::factory()->picMutu()->create())->getJson(route('observations.lookup', 'employees'))->assertForbidden();

        // Editing another unit's observation is not a way into its staff list.
        $foreign = Observation::create([
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id, 'unit_id' => $this->igd->id, 'user_id' => $this->admin->id,
            'observation_date' => now()->toDateString(), 'officer_name' => 'X', 'patient_name' => 'P',
            'medication_compliant' => true, 'procedure_compliant' => true, 'patient_name_verified' => true, 'birth_date_verified' => true,
        ]);
        $this->actingAs($lab)->getJson(route('observations.lookup', ['employees', 'observation' => $foreign->id]))->assertForbidden();

        // HRIS down: the reason, so the user can type the name instead.
        config(['services.hris.url' => '']);
        $this->actingAs($picUnit)->getJson(route('observations.lookup', 'employees'))
            ->assertStatus(503)->assertJsonPath('message', 'Integrasi HRIS belum dikonfigurasi.');
    }

    public function test_chosen_employee_is_stored_as_the_officers_name(): void
    {
        $this->fakeHris();
        $this->map($this->igd, self::NURSING);
        $picUnit = User::factory()->picUnit($this->igd)->create();

        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput([
            'officer_name' => 'ditimpa',
            'officer_name__employee' => $this->employeeId('Siti Hadiyanti'),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('observations.create'));

        $observation = Observation::sole();
        $this->assertSame('Siti Hadiyanti', $observation->officer_name);
        $this->assertSame('Siti Hadiyanti', $observation->values()->whereHas('field', fn ($q) => $q->where('key', 'officer_name'))->value('value'));
        $this->assertStringNotContainsString('6371', json_encode($observation->fresh()->toArray()));

        // The edit page preselects that employee.
        $this->actingAs($picUnit)->get(route('observations.edit', $observation))->assertOk()
            ->assertSee('name="officer_name__employee" value="'.$this->employeeId('Siti Hadiyanti').'"', false)
            ->assertSee('Siti Hadiyanti');
    }

    public function test_manual_entry_and_unknown_or_foreign_employees(): void
    {
        $this->fakeHris();
        $this->map($this->igd, self::NURSING);
        $picUnit = User::factory()->picUnit($this->igd)->create();

        // Manual: the typed name is stored as before.
        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput([
            'officer_name' => 'Petugas Magang', 'officer_name__employee' => EmployeePicker::MANUAL,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Petugas Magang', Observation::sole()->officer_name);

        // An employee of another unit cannot be submitted through the dropdown.
        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput([
            'officer_name__employee' => $this->employeeId('Budi Santoso'),
        ]))->assertSessionHasErrors(['officer_name' => 'Petugas yang dipilih tidak terdaftar pada unit ini. Pilih ulang dari daftar.']);

        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput(['officer_name__employee' => 'palsu']))
            ->assertSessionHasErrors('officer_name');

        $this->assertSame(1, Observation::count());
    }

    public function test_form_falls_back_to_typing_when_hris_is_unavailable(): void
    {
        $picUnit = User::factory()->picUnit($this->igd)->create();
        $ppe = $this->inm[ObservationForms::PPE];

        // Unit not mapped: no call to HRIS, plain input with a hint.
        Http::fake([self::URL.'/*' => Http::response(['success' => false, 'message' => 'x', 'errors' => null], 503)]);
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $ppe))
            ->assertOk()
            ->assertSee('type="text" name="officer_name"', false)
            ->assertSee('belum dipetakan ke departemen HRIS');
        Http::assertNothingSent();

        // HRIS down: plain input, the reason, and saving still works.
        $this->map($this->igd, self::NURSING);
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $ppe))
            ->assertOk()
            ->assertDontSee('__employee')
            ->assertSee('HRIS sedang mengalami gangguan');

        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput())->assertSessionHasNoErrors();
        $this->assertSame('Nadia Contoh', Observation::sole()->officer_name);

        // A dropdown choice submitted while HRIS is down is refused with a way out.
        $this->actingAs($picUnit)->post(route('observations.store'), $this->ppeInput(['officer_name__employee' => 'abc']))
            ->assertSessionHasErrors('officer_name');

        // Not configured at all: the form is exactly as before.
        config(['services.hris.url' => '']);
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $ppe))
            ->assertOk()
            ->assertDontSee('__employee')
            ->assertDontSee('departemen HRIS');
    }

    public function test_edit_uses_the_observations_unit_and_keeps_names_outside_the_list(): void
    {
        $this->fakeHris();
        $this->map($this->igd, self::NURSING);
        $this->map($this->lab, self::LAB);

        $legacy = Observation::create([
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $this->igd->id,
            'user_id' => $this->admin->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Nama Lama Tidak Di HRIS',
            'patient_name' => 'Pasien',
            'medication_compliant' => true, 'procedure_compliant' => true, 'patient_name_verified' => true, 'birth_date_verified' => true,
        ]);

        // Admin (no unit of their own) edits an IGD observation: IGD's staff, and the
        // stored name stays selected as a manual entry.
        $html = $this->actingAs($this->admin)->get(route('observations.edit', $legacy))
            ->assertOk()
            ->assertSee('name="officer_name__employee" value="__manual"', false)
            ->getContent();
        $this->assertStringContainsString('value="Nama Lama Tidak Di HRIS"', $html);
        // The dropdown searches the observation's unit.
        $this->assertStringContainsString('\u0022observation\u0022:'.$legacy->id, $html);
        $this->actingAs($this->admin)->getJson(route('observations.lookup', ['employees', 'observation' => $legacy->id]))
            ->assertOk()
            ->assertJsonPath('data.*.label', ['Ahmad Fauzi', 'Rina Tanpa NIP', 'Siti Hadiyanti']);

        $this->actingAs($this->admin)->put(route('observations.update', $legacy), [
            'observation_date' => now()->toDateString(),
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
            'officer_name__employee' => $this->employeeId('Ahmad Fauzi'),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ahmad Fauzi', $legacy->fresh()->officer_name);
    }

    // --- PIC Unit link on user management --------------------------------------------

    private function anyEmployeeId(string $name): string
    {
        return collect($this->service()->directory()['employees'])->firstWhere('name', $name)['id'];
    }

    public function test_user_form_offers_every_active_employee_of_both_companies_by_name_only(): void
    {
        $this->fakeHris();
        $usersBefore = User::count();

        $html = $this->actingAs($this->admin)->get(route('users.create'))
            ->assertOk()
            ->assertSee('PIC Unit (Karyawan HRIS)')
            ->assertSee('Ahmad Fauzi')
            ->assertSee('Budi Santoso')
            ->assertSee('Dewi Banjarbaru') // company 0002
            ->assertDontSee('19870100001')
            ->assertDontSee('19900200001')
            ->assertDontSee('Departemen Keperawatan')
            ->assertDontSee('PT Contoh Perusahaan HRIS')
            ->getContent();

        $this->assertStringContainsString($this->anyEmployeeId('Dewi Banjarbaru'), $html);

        // Lists come from the cache: opening the form again does not call HRIS.
        $sent = count(Http::recorded());
        $this->actingAs($this->admin)->get(route('users.edit', $this->admin))->assertOk()->assertSee('Dewi Banjarbaru');
        $this->assertCount($sent, Http::recorded());

        // Listing employees never creates accounts for them.
        $this->assertSame($usersBefore, User::count());
    }

    public function test_choosing_an_employee_returns_name_and_hris_nip(): void
    {
        $this->directory['0002'][] = $this->person('00120000009', 'Nol Di Depan', null, null);
        $this->fakeHris();
        $id = $this->anyEmployeeId('Nol Di Depan');
        $requests = count(Http::recorded());

        $this->actingAs($this->admin)->getJson(route('users.hris-employee', $id))
            ->assertOk()
            ->assertExactJson(['name' => 'Nol Di Depan', 'nip' => '00120000009'])
            ->assertHeader('Cache-Control', 'no-store, private');

        // One name search in the employee's company, and the NIP is not cached.
        $this->assertCount($requests + 1, Http::recorded());
        Http::assertSent(fn (Request $request) => ($request->data()['kode'] ?? null) === '0002' && ($request->data()['nama'] ?? null) === 'Nol Di Depan');
        $everything = json_encode(collect(array_keys((fn () => $this->storage)->call(Cache::store()->getStore())))->map(fn ($key) => Cache::get($key)));
        $this->assertStringNotContainsString('00120000009', $everything);

        // An employee without a NIP: name only, NIP stays manual.
        $this->actingAs($this->admin)->getJson(route('users.hris-employee', $this->anyEmployeeId('Rina Tanpa NIP')))
            ->assertExactJson(['name' => 'Rina Tanpa NIP', 'nip' => null]);

        $this->actingAs($this->admin)->getJson(route('users.hris-employee', str_repeat('c', 32)))->assertNotFound();

        // Admin only.
        foreach ([User::factory()->picMutu()->create(), User::factory()->picUnit($this->igd)->create()] as $other) {
            $this->actingAs($other)->getJson(route('users.hris-employee', $id))->assertForbidden();
        }
    }

    public function test_linking_fills_name_and_nip_from_hris_and_changes_nothing_else(): void
    {
        $this->directory['0002'][] = $this->person('00120000009', 'Nol Di Depan', null, null);
        $this->fakeHris();
        $picUnit = User::factory()->picUnit($this->igd)->create(['name' => 'Nama Akun', 'nip' => '123', 'email' => 'akun@example.test']);
        $before = $picUnit->only(['role', 'unit_id', 'password', 'email']);
        $base = ['role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id, 'email' => 'akun@example.test'];
        $nol = $this->anyEmployeeId('Nol Di Depan');

        // Name and NIP that do not match HRIS are refused.
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$base, 'nip' => '123', 'name' => 'Nama Akun', 'hris_employee_id' => $nol])
            ->assertSessionHasErrors(['name', 'nip']);
        // Leading zeros count: the same digits without them do not match.
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$base, 'nip' => '12000000000009', 'name' => 'Nol Di Depan', 'hris_employee_id' => $nol])
            ->assertSessionHasErrors('nip');
        $this->assertNull($picUnit->fresh()->hris_employee_id);

        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$base, 'nip' => '00120000009', 'name' => 'Nol Di Depan', 'hris_employee_id' => $nol])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $picUnit->refresh();
        $this->assertSame('00120000009', $picUnit->nip);
        $this->assertSame('Nol Di Depan', $picUnit->name);
        $this->assertSame([$nol, '0002'], [$picUnit->hris_employee_id, $picUnit->hris_company_code]);
        $this->assertNotNull($picUnit->hris_linked_at);
        $this->assertSame($before, $picUnit->only(['role', 'unit_id', 'password', 'email']));
        $this->assertArrayNotHasKey('hris_employee_id', $picUnit->toArray());

        // The edit form shows the linked name and locks Name/NIP; the index marks the account.
        $this->actingAs($this->admin)->get(route('users.edit', $picUnit))
            ->assertSee('Nol Di Depan')
            ->assertSee('x-bind:readonly="locked"', false);
        $this->actingAs($this->admin)->get(route('users.index'))->assertSee('HRIS');

        // Switching to another employee: Name and NIP must follow.
        $dewi = $this->anyEmployeeId('Dewi Banjarbaru');
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$base, 'nip' => '00120000009', 'name' => 'Nol Di Depan', 'hris_employee_id' => $dewi])
            ->assertSessionHasErrors(['name', 'nip']);
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$base, 'nip' => '19900200001', 'name' => 'Dewi Banjarbaru', 'hris_employee_id' => $dewi])
            ->assertSessionHasNoErrors();
        $this->assertSame(['19900200001', 'Dewi Banjarbaru', $dewi], [$picUnit->fresh()->nip, $picUnit->fresh()->name, $picUnit->fresh()->hris_employee_id]);

        // Saving again unchanged needs no HRIS check; changing the NIP while linked is refused.
        $requests = count(Http::recorded());
        $linked = [...$base, 'nip' => '19900200001', 'name' => 'Dewi Banjarbaru', 'hris_employee_id' => $dewi];
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), $linked)->assertSessionHasNoErrors();
        $this->assertCount($requests, Http::recorded());
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$linked, 'nip' => '999'])->assertSessionHasErrors('nip');

        // Unlinking keeps the stored Name and NIP.
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$linked, 'hris_employee_id' => ''])->assertSessionHasNoErrors();
        $picUnit->refresh();
        $this->assertNull($picUnit->hris_employee_id);
        $this->assertNull($picUnit->hris_company_code);
        $this->assertSame(['19900200001', 'Dewi Banjarbaru'], [$picUnit->nip, $picUnit->name]);
    }

    public function test_an_employee_links_to_one_account_and_must_be_active_in_hris(): void
    {
        $this->fakeHris();
        $ahmad = $this->anyEmployeeId('Ahmad Fauzi');
        $first = User::factory()->picUnit($this->igd)->create(['hris_employee_id' => $ahmad, 'hris_company_code' => '0001']);
        $second = User::factory()->picUnit($this->igd)->create(['nip' => '456']);
        $payload = ['nip' => '19870100001', 'name' => 'Ahmad Fauzi', 'role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id];

        $this->actingAs($this->admin)->put(route('users.update', $second), [...$payload, 'hris_employee_id' => $ahmad])
            ->assertSessionHasErrors(['hris_employee_id' => "Karyawan ini sudah terhubung dengan akun {$first->name}. Lepaskan tautan pada akun tersebut terlebih dahulu."]);

        // Unknown identifier, and a raw NIP instead of the identifier.
        $this->actingAs($this->admin)->put(route('users.update', $second), [...$payload, 'hris_employee_id' => str_repeat('a', 32)])
            ->assertSessionHasErrors('hris_employee_id');
        $this->actingAs($this->admin)->put(route('users.update', $second), [...$payload, 'hris_employee_id' => '19870100002'])
            ->assertSessionHasErrors('hris_employee_id');

        $this->assertNull($second->fresh()->hris_employee_id);
        $this->assertSame('456', $second->fresh()->nip);

        // A new account may be linked when the admin creates it, with HRIS's name and NIP.
        $create = [
            'role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id,
            'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123',
            'hris_employee_id' => $this->anyEmployeeId('Budi Santoso'),
        ];
        $this->actingAs($this->admin)->post(route('users.store'), [...$create, 'nip' => '789', 'name' => 'Akun Baru'])
            ->assertSessionHasErrors(['nip', 'name']);
        $this->actingAs($this->admin)->post(route('users.store'), [...$create, 'nip' => '19870100003', 'name' => 'Budi Santoso'])
            ->assertSessionHasNoErrors();
        $this->assertSame('0001', User::where('nip', '19870100003')->value('hris_company_code'));

        // No NIP in HRIS: the name must match, the NIP is typed by the admin.
        $this->actingAs($this->admin)->post(route('users.store'), [...$create, 'nip' => '555', 'name' => 'Rina Tanpa NIP', 'hris_employee_id' => $this->anyEmployeeId('Rina Tanpa NIP')])
            ->assertSessionHasNoErrors();
        $this->assertSame('Rina Tanpa NIP', User::where('nip', '555')->value('name'));
    }

    public function test_link_is_kept_when_hris_is_unavailable(): void
    {
        $this->fakeHris();
        $ahmad = $this->anyEmployeeId('Ahmad Fauzi');
        $picUnit = User::factory()->picUnit($this->igd)->create(['nip' => '123', 'hris_employee_id' => $ahmad, 'hris_company_code' => '0001']);
        $payload = ['nip' => '123', 'name' => $picUnit->name, 'role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id];

        // HRIS down and nothing cached: the form says so, and re-saving keeps the link.
        Cache::flush();
        config(['services.hris.url' => 'https://down.test']);
        Http::fake(['https://down.test/*' => Http::response([], 500)]);

        $this->actingAs($this->admin)->get(route('users.edit', $picUnit))
            ->assertOk()
            ->assertSee('HRIS sedang mengalami gangguan');
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$payload, 'hris_employee_id' => $ahmad])
            ->assertSessionHasNoErrors();
        $this->assertSame($ahmad, $picUnit->fresh()->hris_employee_id);

        // Choosing someone else while HRIS is down is refused rather than guessed.
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), [...$payload, 'hris_employee_id' => str_repeat('b', 32)])
            ->assertSessionHasErrors('hris_employee_id');

        // Not configured: no field, and the link is untouched.
        config(['services.hris.url' => '']);
        $this->actingAs($this->admin)->get(route('users.edit', $picUnit))->assertOk()->assertDontSee('PIC Unit (Karyawan HRIS)');
        $this->actingAs($this->admin)->put(route('users.update', $picUnit), $payload)->assertSessionHasNoErrors();
        $this->assertSame($ahmad, $picUnit->fresh()->hris_employee_id);
    }

    // --- HRIS identity field: `nip` (was `nik`) ----------------------------------------

    public function test_only_the_nip_field_is_read_and_a_legacy_nik_is_ignored(): void
    {
        // A row still carrying the old field: its NIK must never become a login NIP.
        $this->directory['0002'][] = ['nik' => '6372000000000099', 'nama' => 'Masih Pakai NIK', 'divisi' => null, 'departemen' => null, 'perusahaan' => 'PT Contoh Perusahaan HRIS'];
        $this->fakeHris();

        $this->actingAs($this->admin)->getJson(route('users.hris-employee', $this->anyEmployeeId('Masih Pakai NIK')))
            ->assertExactJson(['name' => 'Masih Pakai NIK', 'nip' => null]);
        $this->actingAs($this->admin)->getJson(route('users.hris-employee', $this->anyEmployeeId('Dewi Banjarbaru')))
            ->assertExactJson(['name' => 'Dewi Banjarbaru', 'nip' => '19900200001']);

        // Identifiers come from the NIP, so two people with the same name stay apart.
        $this->directory['0002'][] = $this->person('19900200002', 'Dewi Banjarbaru', null, null);
        Cache::flush();
        $this->assertCount(2, collect($this->service()->employees('0002'))->where('name', 'Dewi Banjarbaru'));
    }

    public function test_hris_nip_with_a_letter_prefix_is_accepted_only_from_hris(): void
    {
        $this->directory['0001'][] = $this->person('PK123456789', 'Perawat Kontrak', null, null);
        $this->fakeHris();
        $id = $this->anyEmployeeId('Perawat Kontrak');
        $create = ['role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id, 'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123'];

        // Typed by hand: digits only, as before.
        $this->actingAs($this->admin)->post(route('users.store'), [...$create, 'nip' => 'PK123456789', 'name' => 'Ketik Manual'])
            ->assertSessionHasErrors(['nip' => 'NIP hanya boleh berisi angka.']);

        // From HRIS: kept exactly as HRIS sends it, and it can log in.
        $this->actingAs($this->admin)->post(route('users.store'), [...$create, 'nip' => 'PK123456789', 'name' => 'Perawat Kontrak', 'hris_employee_id' => $id])
            ->assertSessionHasNoErrors();
        $this->assertSame('PK123456789', User::where('name', 'Perawat Kontrak')->value('nip'));

        auth()->logout();
        $this->post(route('login'), ['nip' => 'PK123456789', 'password' => 'password-baru-123'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs(User::where('nip', 'PK123456789')->first());
    }

    public function test_hris_nip_already_used_by_another_account_is_refused(): void
    {
        $this->fakeHris();
        // An existing account already logs in with this NIP (e.g. typed before HRIS linking).
        $existing = User::factory()->picUnit($this->igd)->create(['nip' => '19870100002', 'name' => 'Akun Lama']);
        $other = User::factory()->picUnit($this->lab)->create(['nip' => '777', 'name' => 'Akun Lain']);
        $before = $other->only(['nip', 'name', 'role', 'unit_id', 'email', 'password']);

        $this->actingAs($this->admin)->put(route('users.update', $other), [
            'nip' => '19870100002', 'name' => 'Siti Hadiyanti', 'role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->lab->id,
            'hris_employee_id' => $this->anyEmployeeId('Siti Hadiyanti'),
        ])->assertSessionHasErrors('nip');

        $this->assertSame($before, $other->fresh()->only(['nip', 'name', 'role', 'unit_id', 'email', 'password']));
        $this->assertNull($other->fresh()->hris_employee_id);
        $this->assertSame('Akun Lama', $existing->fresh()->name);

        // Linking the account that already has that NIP works and changes nothing but the link and name.
        $this->actingAs($this->admin)->put(route('users.update', $existing), [
            'nip' => '19870100002', 'name' => 'Siti Hadiyanti', 'role' => User::ROLE_PIC_UNIT, 'unit_id' => $this->igd->id,
            'hris_employee_id' => $this->anyEmployeeId('Siti Hadiyanti'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(['19870100002', 'Siti Hadiyanti', $this->igd->id], [$existing->fresh()->nip, $existing->fresh()->name, $existing->fresh()->unit_id]);
    }

    public function test_lists_cached_under_the_old_identifier_scheme_are_not_used(): void
    {
        $this->fakeHris();
        $oldKey = 'hris:employees:0001:'.substr(sha1(self::URL.'|simutu'), 0, 12);
        Cache::put($oldKey, [['id' => str_repeat('0', 32), 'name' => 'Daftar Lama', 'division' => null, 'department' => null]], 3600);

        $this->assertNotContains('Daftar Lama', array_column($this->service()->employees('0001'), 'name'));
        Http::assertSent(fn (Request $request) => ($request->data()['kode'] ?? null) === '0001');
    }
}

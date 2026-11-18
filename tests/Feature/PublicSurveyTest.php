<?php

namespace Tests\Feature;

use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\PublicSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\PublishesPatientSurvey;
use Tests\TestCase;

/**
 * The public patient satisfaction survey (/survei-kepuasan-pasien): open
 * without login, only that form, stored for Humas without a user.
 */
class PublicSurveyTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, PublishesPatientSurvey, RefreshDatabase;

    private User $admin;

    private Unit $humas;

    private IndicatorFormVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();
        $this->humas = Unit::create(['code' => 'HUMAS', 'name' => 'Humas', 'is_active' => true]);
        $this->version = $this->publishPatientSurvey($this->admin);
        config(['survey.per_minute' => 100, 'survey.per_hour' => 1000]);
        RateLimiter::clear('minute:127.0.0.1');
        RateLimiter::clear('hour:127.0.0.1');
        auth()->logout();
    }

    private function key(string $external): string
    {
        return $this->version->fields->firstWhere('external_id', $external)->key;
    }

    private function option(string $external, string $label): string
    {
        return $this->version->fields->firstWhere('external_id', $external)->options->firstWhere('label', $label)->value;
    }

    /** Answers to the questions every patient gets. */
    private function common(): array
    {
        $answers = [
            'form_version_id' => $this->version->id,
            $this->key('1') => $this->option('1', 'Perempuan'),
            $this->key('2') => '35',
            $this->key('3') => $this->option('3', 'SMA(K)'),
            $this->key('4') => $this->option('4', 'SWASTA'),
            $this->key('42') => 'Tidak ada',
            $this->key('43') => 'Pertahankan pelayanan',
            $this->key('44') => $this->option('44', 'YA'),
            $this->key('46') => $this->option('46', 'TIDAK'),
            $this->key('50') => '081234567890',
            $this->key('49') => '90',
        ];

        foreach (['32:0', '33:0', '34:0', '35:0', '36:0', '37:0', '38:0', '39:0', '40:0'] as $rating) {
            $answers[$this->key($rating)] = $this->version->fields->firstWhere('external_id', $rating)->options[3]->value;
        }

        return $answers;
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function services(): array
    {
        return [
            'Rawat Inap' => ['Rawat Inap', ['53' => 'Kelas 1', '8' => 'date', '12' => '3 gedung B']],
            'Rawat Jalan/Poliklinik' => ['Rawat Jalan/Poliklinik', ['11' => 'date', '9' => 'POLI MATA']],
            'IGD' => ['IGD', ['13' => 'date']],
            'Lainnya' => ['Lainnya', ['14' => 'text:Laboratorium', '15' => 'date']],
        ];
    }

    /** Answers of one service's own questions. */
    private function branch(array $spec): array
    {
        $answers = [];

        foreach ($spec as $external => $value) {
            $answers[$this->key($external)] = match (true) {
                $value === 'date' => now()->toDateString(),
                str_starts_with($value, 'text:') => substr($value, 5),
                default => $this->option($external, $value),
            };
        }

        return $answers;
    }

    // --- open to the public ------------------------------------------------------------

    public function test_guests_open_the_survey_without_logging_in(): void
    {
        $this->assertGuest();

        $html = $this->get('/survei-kepuasan-pasien')->assertOk()
            ->assertSee('Survei Kepuasan Pasien')
            ->assertSee('Kirim Survei')
            ->assertSee('name="_token"', false)
            ->assertSee('data-visible-when', false)
            ->assertSee('Bagaimana pendapat Saudara tentang kewajaran biaya/tarif dalam pelayanan')
            // The date is set by the server; nothing internal is linked or shown.
            ->assertDontSee('name="observation_date"', false)
            ->assertDontSee(route('dashboard'))
            ->assertDontSee(route('observations.index'))
            ->assertDontSee(route('login'))
            ->assertDontSee('Laporan Observasi')
            ->getContent();

        // The submit button closes the form, after the last question, and the footer follows it.
        $this->assertMatchesRegularExpression('/data-survey-submit[^>]*>\s*<svg[\s\S]*?<\/svg>\s*<span>Kirim Survei<\/span>\s*<\/button>\s*<\/div>\s*<\/form>/', $html);
        $this->assertLessThan(strpos($html, '<footer'), strpos($html, 'data-survey-submit'));
        $this->assertGreaterThan(strpos($html, $this->key('49')), strpos($html, 'data-survey-submit'));
        $this->assertStringContainsString('class="btn btn-primary h-12 w-full', $html);
    }

    // --- submitting ----------------------------------------------------------------------

    #[DataProvider('services')]
    public function test_every_service_is_submitted_and_stored_for_humas_without_a_user(string $service, array $spec): void
    {
        $own = $this->branch($spec);
        $others = [];
        foreach (self::services() as [$other, $otherSpec]) {
            if ($other !== $service) {
                $others += $this->branch($otherSpec);
            }
        }

        $this->post('/survei-kepuasan-pasien', [
            ...$this->common(),
            $this->key('6') => $this->option('6', $service),
            ...$own,
            ...$others, // other services' answers sent along are ignored
            // Never taken from the request:
            'indicator_id' => 999, 'unit_id' => 999, 'user_id' => $this->admin->id, 'observation_date' => '2020-01-01',
        ])->assertRedirect('/survei-kepuasan-pasien')->assertSessionHasNoErrors()->assertSessionHas('survey_sent', true);

        $observation = Observation::sole();
        $this->assertSame([$this->version->indicator_id, $this->version->id, $this->humas->id, null, now()->toDateString()], [
            $observation->indicator_id, $observation->indicator_form_version_id, $observation->unit_id, $observation->user_id, $observation->observation_date->toDateString(),
        ]);
        $stored = $observation->values()->with('field')->get()->pluck('value', 'field.key')->all();
        foreach ($own as $key => $value) {
            $this->assertSame($value, $stored[$key]);
        }
        $this->assertSame([], array_values(array_intersect(array_keys($others), array_keys($stored))));
        $this->assertSame('081234567890', $stored[$this->key('50')]);

        // A thank-you, with nothing of the answers shown back.
        $this->get('/survei-kepuasan-pasien')->assertSee('Terima kasih')->assertSee('Isi survei lagi')
            ->assertDontSee('081234567890')->assertDontSee('Pertahankan pelayanan');
    }

    public function test_conditional_questions_follow_the_answers(): void
    {
        // "Pekerjaan lainnya" is required for LAINNYA only.
        $this->post('/survei-kepuasan-pasien', [...$this->common(), $this->key('4') => $this->option('4', 'LAINNYA'),
            $this->key('6') => $this->option('6', 'IGD'), ...$this->branch(['13' => 'date'])])
            ->assertSessionHasErrors($this->key('5'));

        // Each service asks for exactly its own questions.
        foreach (self::services() as [$service, $spec]) {
            $this->post('/survei-kepuasan-pasien', [...$this->common(), $this->key('6') => $this->option('6', $service)]);
            $this->assertEqualsCanonicalizing(array_keys($this->branch($spec)), array_keys(session('errors')->getMessages()), $service);
        }

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_validation_errors_keep_the_answers(): void
    {
        $answers = [...$this->common(), $this->key('6') => $this->option('6', 'Rawat Inap'), $this->key('49') => '150'];

        $this->post('/survei-kepuasan-pasien', $answers)
            ->assertRedirect('/survei-kepuasan-pasien')
            ->assertSessionHasErrors([$this->key('49'), $this->key('53'), $this->key('8'), $this->key('12')])
            ->assertSessionHas('error', 'Survei belum terkirim. Lengkapi jawaban yang ditandai.');
        $this->assertDatabaseCount('observations', 0);

        // Back on the page: the answers given are still chosen, the Rawat Inap questions open.
        $html = $this->get('/survei-kepuasan-pasien')->assertOk()->assertSee('Periksa kembali isian yang ditandai.')->getContent();
        $this->assertMatchesRegularExpression('/name="'.$this->key('6').'" value="'.$this->option('6', 'Rawat Inap').'"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="'.$this->key('1').'" value="'.$this->option('1', 'Perempuan').'"\s+checked/', $html);
        $this->assertStringContainsString('Pertahankan pelayanan', $html);
        $this->assertMatchesRegularExpression('/data-field="'.$this->key('53').'"\s+data-visible-when="[^"]+"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-field="'.$this->key('13').'"\s+data-visible-when="[^"]+"\s+hidden/', $html);
    }

    // --- security ------------------------------------------------------------------------

    public function test_spam_protection_csrf_and_a_replaced_form(): void
    {
        // CSRF: the route is in the web group, whose middleware checks the token.
        $this->assertContains('web', Route::getRoutes()->getByName('survey.store')->gatherMiddleware());
        $this->assertContains('throttle:public-survey', Route::getRoutes()->getByName('survey.store')->gatherMiddleware());

        // Honeypot filled in: looks fine to the bot, nothing stored.
        $this->post('/survei-kepuasan-pasien', [...$this->common(), 'website' => 'http://spam.example'])
            ->assertRedirect('/survei-kepuasan-pasien')->assertSessionHas('survey_sent');
        $this->assertDatabaseCount('observations', 0);

        // A form that was replaced meanwhile: asked to check and send again.
        $this->post('/survei-kepuasan-pasien', [...$this->common(), 'form_version_id' => 12345])
            ->assertSessionHas('error', 'Formulir survei baru saja diperbarui. Periksa kembali jawaban Anda, lalu kirim ulang.');
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_submissions_are_rate_limited_per_visitor(): void
    {
        config(['survey.per_minute' => 2]);
        $valid = [...$this->common(), $this->key('6') => $this->option('6', 'IGD'), ...$this->branch(['13' => 'date'])];

        $this->post('/survei-kepuasan-pasien', $valid)->assertSessionHasNoErrors();
        $this->post('/survei-kepuasan-pasien', $valid)->assertSessionHasNoErrors();
        $this->post('/survei-kepuasan-pasien', $valid)
            ->assertRedirect('/survei-kepuasan-pasien')
            ->assertSessionHas('error', 'Terlalu banyak pengiriman dari perangkat ini. Silakan coba lagi beberapa saat lagi.');

        $this->assertDatabaseCount('observations', 2);
        // Opening the page is not limited.
        $this->get('/survei-kepuasan-pasien')->assertOk();
    }

    public function test_survey_is_unavailable_until_published_active_and_its_unit_exists(): void
    {
        $indicator = $this->version->indicator;

        $indicator->update(['is_active' => false]);
        $this->get('/survei-kepuasan-pasien')->assertStatus(503)->assertSee('Survei belum tersedia')->assertDontSee('Kirim Survei');
        $this->post('/survei-kepuasan-pasien', $this->common())->assertStatus(503);
        $indicator->update(['is_active' => true]);

        $this->humas->update(['is_active' => false]);
        $this->get('/survei-kepuasan-pasien')->assertStatus(503);
        $this->humas->update(['is_active' => true]);

        $this->version->update(['status' => IndicatorFormVersion::STATUS_ARCHIVED]);
        $this->get('/survei-kepuasan-pasien')->assertStatus(503);
        $this->assertDatabaseCount('observations', 0);
    }

    // --- nothing else is public ------------------------------------------------------------

    public function test_internal_pages_still_require_login(): void
    {
        $this->post('/survei-kepuasan-pasien', [...$this->common(), $this->key('6') => $this->option('6', 'IGD'), ...$this->branch(['13' => 'date'])]);
        $observation = Observation::sole();
        auth()->logout();

        foreach ([
            route('dashboard'),
            route('observations.index', ['kategori' => 'inm', 'indicator_id' => $observation->indicator_id]),
            route('observations.create'),
            route('observations.create.indicator', $observation->indicator_id),
            route('observations.show', $observation),
            route('observations.edit', $observation),
            route('observations.export', ['format' => 'excel', 'indicator_id' => $observation->indicator_id]),
            route('observations.lookup', 'employees'),
            route('form-builder.show', $observation->indicator_id),
            route('indicator-unit-mappings.index'),
            route('users.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->post(route('observations.store'), ['indicator_id' => $observation->indicator_id])->assertRedirect(route('login'));
        $this->assertSame(1, Observation::count());
    }

    public function test_reports_show_public_answers_as_such_and_logged_in_staff_can_open_the_survey(): void
    {
        $this->post('/survei-kepuasan-pasien', [...$this->common(), $this->key('6') => $this->option('6', 'IGD'), ...$this->branch(['13' => 'date'])]);
        $observation = Observation::sole();

        $this->actingAs($this->admin)->get(route('observations.index', [
            'kategori' => 'inm', 'indicator_id' => $observation->indicator_id,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->toDateString(),
        ]))->assertOk()->assertSee(PublicSurvey::SUBMITTER)->assertSee('Humas');
        $this->actingAs($this->admin)->get(route('observations.show', $observation))->assertOk()->assertSee(PublicSurvey::SUBMITTER);

        // Staff may open the public page too (e.g. to help a patient).
        $this->actingAs($this->admin)->get('/survei-kepuasan-pasien')->assertOk()->assertSee('Kirim Survei');

        // The other observations' data never appears on the public page.
        DB::table('observations')->update(['updated_at' => now()]);
        auth()->logout();
        $this->get('/survei-kepuasan-pasien')->assertDontSee('081234567890');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/', '/dashboard', '/observations', '/observations/create', '/units', '/users', '/indicators/inm'] as $url) {
            $this->get($url)->assertRedirect();
        }

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_master_data_is_admin_only(): void
    {
        $this->createInmIndicators();
        $unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);

        foreach ([User::factory()->picMutu()->create(), User::factory()->picUnit($unit)->create()] as $user) {
            $this->actingAs($user)->get('/units')->assertForbidden();
            $this->actingAs($user)->post('/units', ['code' => 'X', 'name' => 'X'])->assertForbidden();
            $this->actingAs($user)->patch("/units/{$unit->id}/status")->assertForbidden();
            $this->actingAs($user)->get('/users')->assertForbidden();
            $this->actingAs($user)->post('/users', [])->assertForbidden();
            $this->actingAs($user)->get('/indicators/imp-rs/create')->assertForbidden();
        }

        $this->assertTrue($unit->fresh()->is_active);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/units')->assertOk();
        $this->actingAs($admin)->get('/users')->assertOk();
    }

    public function test_indicator_list_is_visible_to_admin_and_pic_mutu_only(): void
    {
        $this->createInmIndicators();

        $this->actingAs(User::factory()->picMutu()->create())->get('/indicators/inm')
            ->assertOk()
            ->assertDontSee('Tambah Indikator');
        $this->actingAs(User::factory()->admin()->create())->get('/indicators/inm')->assertOk();
        $this->actingAs(User::factory()->picUnit()->create())->get('/indicators/inm')->assertForbidden();
    }

    public function test_admin_pages_render(): void
    {
        $this->createInmIndicators();
        $this->seedImpCategories();
        $unit = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $admin = User::factory()->admin()->create(['unit_id' => $unit->id]);
        $other = User::factory()->picUnit($unit)->create(['nip' => null]);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Dashboard Mutu');
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('Belum ada NIP');
        $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('name="nip"', false);
        $this->actingAs($admin)->get("/users/{$other->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/units')->assertOk()->assertSee('Instalasi Gawat Darurat');
        $this->actingAs($admin)->get('/indicators/imp-unit')->assertOk()->assertSee('Tambah Indikator');
        $this->actingAs($admin)->get('/indicators/imp-unit/create')->assertOk()->assertSee('name="unit_id"', false);
    }

    public function test_imp_unit_indicator_stores_responsible_unit(): void
    {
        $this->seedImpCategories();
        $unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/indicators/imp-unit', ['name' => 'Waktu Tanggap IGD'])
            ->assertSessionHasErrors('unit_id');

        $this->actingAs($admin)->post('/indicators/imp-unit', ['name' => 'Waktu Tanggap IGD', 'unit_id' => $unit->id])
            ->assertRedirect('/indicators/imp-unit');

        $this->assertDatabaseHas('indicators', ['name' => 'Waktu Tanggap IGD', 'unit_id' => $unit->id]);
    }

    public function test_unknown_role_has_no_observation_access(): void
    {
        $user = User::factory()->create(['role' => 'guest']);

        $this->actingAs($user)->get('/observations')->assertForbidden();
        $this->actingAs($user)->get('/observations/create')->assertForbidden();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    private function seedImpCategories(): void
    {
        foreach (['IMP_RS' => 'imp-rs', 'IMP_UNIT' => 'imp-unit'] as $code => $slug) {
            DB::table('indicator_categories')->insert([
                'code' => $code,
                'slug' => $slug,
                'name' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

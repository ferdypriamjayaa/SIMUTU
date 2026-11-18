<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);
    }

    public function test_admin_can_create_pic_unit_account_that_logs_in_with_nip(): void
    {
        $response = $this->actingAs($this->admin)->post(route('users.store'), [
            'nip' => '0012345',
            'name' => 'Perawat IGD',
            'email' => '',
            'role' => User::ROLE_PIC_UNIT,
            'unit_id' => $this->unit->id,
            'password' => 'rahasia-123',
            'password_confirmation' => 'rahasia-123',
        ]);

        $response->assertRedirect(route('users.index'));
        $user = User::where('nip', '0012345')->sole();
        $this->assertNull($user->email);
        $this->assertSame($this->unit->id, $user->unit_id);
        $this->assertTrue(Hash::check('rahasia-123', $user->password));

        auth()->logout();
        $this->post('/login', ['nip' => '0012345', 'password' => 'rahasia-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_nip_must_be_unique_numeric_and_pic_unit_needs_unit(): void
    {
        User::factory()->create(['nip' => '111']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'nip' => '111',
            'name' => 'Duplikat',
            'role' => User::ROLE_PIC_UNIT,
            'password' => 'rahasia-123',
            'password_confirmation' => 'rahasia-123',
        ])->assertSessionHasErrors(['nip', 'unit_id']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'nip' => '12-AB',
            'name' => 'Format Salah',
            'role' => User::ROLE_PIC_MUTU,
            'password' => 'rahasia-123',
            'password_confirmation' => 'rahasia-123',
        ])->assertSessionHasErrors('nip');
    }

    public function test_inactive_unit_cannot_be_assigned_to_new_account(): void
    {
        $this->unit->update(['is_active' => false]);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'nip' => '222',
            'name' => 'PIC',
            'role' => User::ROLE_PIC_UNIT,
            'unit_id' => $this->unit->id,
            'password' => 'rahasia-123',
            'password_confirmation' => 'rahasia-123',
        ])->assertSessionHasErrors('unit_id');
    }

    public function test_admin_can_assign_nip_to_existing_account_without_changing_password(): void
    {
        $legacy = User::factory()->create(['nip' => null]);
        $hash = $legacy->password;

        $this->actingAs($this->admin)->put(route('users.update', $legacy), [
            'nip' => '987654',
            'name' => $legacy->name,
            'email' => $legacy->email,
            'role' => User::ROLE_PIC_MUTU,
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect(route('users.index'));

        $legacy->refresh();
        $this->assertSame('987654', $legacy->nip);
        $this->assertSame($hash, $legacy->password);
    }

    public function test_admin_cannot_deactivate_or_demote_self(): void
    {
        $this->actingAs($this->admin)->patch(route('users.toggle-status', $this->admin));
        $this->assertTrue($this->admin->fresh()->is_active);

        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'nip' => $this->admin->nip,
            'name' => $this->admin->name,
            'role' => User::ROLE_PIC_MUTU,
        ])->assertSessionHasErrors('role');
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_admin_can_delete_account_without_observations(): void
    {
        $user = User::factory()->picUnit($this->unit)->create();

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertSee('action="'.route('users.destroy', $user).'"', false)
            ->assertDontSee('action="'.route('users.destroy', $this->admin).'"', false);

        $this->actingAs($this->admin)->delete(route('users.destroy', $user))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($user);
    }

    public function test_account_with_observations_cannot_be_deleted(): void
    {
        $user = User::factory()->picUnit($this->unit)->create();
        $categoryId = DB::table('indicator_categories')->insertGetId([
            'code' => 'INM', 'slug' => 'inm', 'name' => 'INM', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $indicator = Indicator::create(['indicator_category_id' => $categoryId, 'name' => 'Indikator', 'is_active' => true]);
        Observation::create([
            'indicator_id' => $indicator->id,
            'unit_id' => $this->unit->id,
            'user_id' => $user->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas',
            'patient_name' => 'Pasien',
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('users.index'))
            ->delete(route('users.destroy', $user))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Nonaktifkan'));

        $this->assertModelExists($user);
        $this->assertSame(1, $user->observations()->count());
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $this->actingAs($this->admin)
            ->from(route('users.index'))
            ->delete(route('users.destroy', $this->admin))
            ->assertSessionHas('error');

        $this->assertModelExists($this->admin);
    }

    public function test_only_admin_can_delete_accounts(): void
    {
        $target = User::factory()->picUnit($this->unit)->create();

        foreach ([User::factory()->picMutu()->create(), User::factory()->picUnit($this->unit)->create()] as $user) {
            $this->actingAs($user)->delete(route('users.destroy', $target))->assertForbidden();
        }

        $this->assertModelExists($target);
    }

    public function test_assign_nip_command(): void
    {
        $legacy = User::factory()->create(['nip' => null, 'email' => 'lama@example.com']);

        $this->artisan('simutu:assign-nip')->expectsOutputToContain('lama@example.com')->assertSuccessful();
        $this->artisan('simutu:assign-nip', ['user' => 'lama@example.com', 'nip' => '000777'])->assertSuccessful();
        $this->assertSame('000777', $legacy->fresh()->nip);

        $other = User::factory()->create(['nip' => null]);
        $this->artisan('simutu:assign-nip', ['user' => (string) $other->id, 'nip' => '000777'])->assertFailed();
        $this->assertNull($other->fresh()->nip);
    }
}

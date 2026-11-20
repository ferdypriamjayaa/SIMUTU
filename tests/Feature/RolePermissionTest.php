<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = Unit::create(['code' => 'IGD', 'name' => 'IGD', 'is_active' => true]);
    }

    // ---- Default roles -------------------------------------------------------

    public function test_default_roles_are_migrated_with_their_permissions(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'pic_mutu', 'pic_unit', 'validator'], Role::pluck('slug')->all());

        $admin = User::factory()->admin()->create();
        $this->assertEqualsCanonicalizing(Permissions::all(), $admin->effectivePermissions());

        $picUnit = User::factory()->picUnit($this->unit)->create();
        $this->assertTrue($picUnit->hasPermission('observations.create'));
        $this->assertFalse($picUnit->hasPermission(Permissions::VIEW_ALL_UNITS));
        $this->assertFalse($picUnit->hasPermission('users.view'));

        $picMutu = User::factory()->picMutu()->create();
        $this->assertTrue($picMutu->hasPermission('inm.export'));
        $this->assertTrue($picMutu->hasPermission('imp.export'));
        $this->assertFalse($picMutu->hasPermission('inm.create'));
        $this->assertFalse($picMutu->hasPermission('observations.create'));

        $validator = User::factory()->validator()->create();
        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'reports.view', 'inm.view', 'imp.view', 'imp_unit.view'],
            $validator->effectivePermissions(),
        );
    }

    public function test_validator_is_read_only(): void
    {
        $this->createInmIndicators();
        $validator = User::factory()->validator()->create(['unit_id' => $this->unit->id]);

        $this->actingAs($validator)->get('/dashboard')->assertOk();
        $this->actingAs($validator)->get('/observations')->assertOk();
        $this->actingAs($validator)->get('/observations/create')->assertForbidden();
        $this->actingAs($validator)->post('/observations', [])->assertForbidden();
        $this->actingAs($validator)->get('/observations/export/excel')->assertForbidden();
        $this->actingAs($validator)->get('/units')->assertForbidden();
        $this->actingAs($validator)->get('/roles')->assertForbidden();
    }

    // ---- Custom roles --------------------------------------------------------

    public function test_admin_creates_role_and_its_permissions_are_enforced(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/roles', [
            'name' => 'Kepala Ruangan',
            'description' => 'Melihat unit',
            'is_active' => '1',
            'permissions' => ['dashboard.view', 'units.view'],
        ])->assertRedirect();

        $role = Role::where('name', 'Kepala Ruangan')->firstOrFail();
        $this->assertSame('kepala_ruangan', $role->slug);
        $this->assertEqualsCanonicalizing(['dashboard.view', 'units.view'], $role->permissionKeys());

        $head = User::factory()->create(['role' => $role->slug, 'unit_id' => $this->unit->id]);

        $this->actingAs($head)->get('/units')->assertOk()->assertDontSee('Tambah Unit');
        $this->actingAs($head)->post('/units', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->actingAs($head)->patch("/units/{$this->unit->id}/status")->assertForbidden();
        $this->actingAs($head)->delete("/units/{$this->unit->id}")->assertForbidden();
        $this->assertTrue($this->unit->fresh()->is_active);

        // The sidebar only offers what the account may open.
        $this->actingAs($head)->get('/dashboard')->assertOk()
            ->assertSee(route('units.index'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('roles.index'), false)
            ->assertDontSee('Manajemen Akses');
    }

    public function test_inactive_role_grants_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $mutu = User::factory()->picMutu()->create();

        $this->actingAs($admin)->patch('/roles/'.Role::where('slug', 'pic_mutu')->value('id').'/status')->assertRedirect();

        $this->actingAs($mutu->fresh())->get('/dashboard')->assertForbidden();
        $this->actingAs($mutu->fresh())->get('/indicators/inm')->assertForbidden();
    }

    public function test_role_in_use_cannot_be_deleted_until_users_are_moved(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::create(['slug' => 'auditor', 'name' => 'Auditor']);
        $member = User::factory()->create(['role' => 'auditor']);

        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertSessionHas('error');
        $this->assertModelExists($role);

        $member->update(['role' => User::ROLE_PIC_MUTU]);
        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertRedirect(route('roles.index'));
        $this->assertModelMissing($role);
    }

    public function test_system_role_is_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $system = Role::where('slug', 'admin')->firstOrFail();

        $this->actingAs($admin)->get("/roles/{$system->id}/edit")->assertRedirect(route('roles.show', $system));
        $this->actingAs($admin)->put("/roles/{$system->id}", ['name' => 'Admin', 'permissions' => []])->assertSessionHas('error');
        $this->actingAs($admin)->patch("/roles/{$system->id}/status")->assertSessionHas('error');
        $this->actingAs($admin)->delete("/roles/{$system->id}")->assertSessionHas('error');

        $this->assertTrue($system->fresh()->is_active);
        $this->assertCount(count(Permissions::all()), $system->fresh()->permissions);
    }

    // ---- User overrides ------------------------------------------------------

    public function test_user_restriction_overrides_role_grant(): void
    {
        $this->createInmIndicators();
        $picUnit = User::factory()->picUnit($this->unit)->create();
        $picUnit->permissionOverrides()->create(['permission' => 'observations.create', 'is_granted' => false]);

        $this->actingAs($picUnit)->get('/observations/create')->assertForbidden();
        $this->actingAs($picUnit)->get('/observations')->assertOk();
    }

    public function test_user_grant_adds_a_permission(): void
    {
        $picUnit = User::factory()->picUnit($this->unit)->create();
        $this->actingAs($picUnit)->get('/units')->assertForbidden();

        $picUnit->permissionOverrides()->create(['permission' => 'units.view', 'is_granted' => true]);

        $this->actingAs($picUnit->fresh())->get('/units')->assertOk();
    }

    public function test_system_rules_take_precedence_over_overrides(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->permissionOverrides()->create(['permission' => 'users.view', 'is_granted' => false]);
        $this->assertTrue($admin->fresh()->hasPermission('users.view'));

        $inactive = User::factory()->picUnit($this->unit)->inactive()->create();
        $inactive->permissionOverrides()->create(['permission' => 'units.view', 'is_granted' => true]);
        $this->assertSame([], $inactive->fresh()->effectivePermissions());
    }

    public function test_admin_sets_overrides_from_the_user_form(): void
    {
        $admin = User::factory()->admin()->create();
        $picUnit = User::factory()->picUnit($this->unit)->create(['nip' => '1001']);

        $this->actingAs($admin)->get("/users/{$picUnit->id}/edit")->assertOk()
            ->assertSee('Izin Pengguna')
            ->assertSee('name="overrides[units.view]"', false)
            ->assertSee('data-folder="inm"', false)
            ->assertSee('IMP Unit');

        $this->actingAs($admin)->put("/users/{$picUnit->id}", [
            'nip' => '1001',
            'name' => $picUnit->name,
            'role' => User::ROLE_PIC_UNIT,
            'unit_id' => $this->unit->id,
            'overrides' => ['units.view' => 'allow', 'imp.edit' => 'deny', 'reports.view' => 'inherit'],
        ])->assertRedirect(route('users.index'));

        $picUnit = $picUnit->fresh();
        $this->assertEqualsCanonicalizing(
            ['units.view' => true, 'imp.edit' => false],
            $picUnit->permissionOverrides->pluck('is_granted', 'permission')->all(),
        );

        $matrix = $picUnit->permissionMatrix();
        $this->assertTrue($matrix['units.view']['effective']);
        $this->assertFalse($matrix['units.view']['role']);
        $this->assertFalse($matrix['imp.edit']['effective']);
        $this->assertTrue($matrix['imp.edit']['role']);
        $this->assertTrue($matrix['reports.view']['effective']);
        $this->assertNull($matrix['reports.view']['override']);
    }

    public function test_granting_view_all_units_removes_the_unit_requirement(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['nip' => '2002', 'name' => 'Rina', 'role' => User::ROLE_PIC_UNIT, 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'];

        $this->actingAs($admin)->post('/users', $base)->assertSessionHasErrors('unit_id');
        $this->actingAs($admin)->post('/users', $base + ['overrides' => [Permissions::VIEW_ALL_UNITS => 'allow']])->assertSessionHasNoErrors();
    }

    // ---- Unauthorized access and escalation ---------------------------------

    public function test_direct_urls_are_refused_without_permission(): void
    {
        $role = Role::where('slug', 'pic_unit')->firstOrFail();
        $picUnit = User::factory()->picUnit($this->unit)->create();
        $other = User::factory()->picMutu()->create();

        foreach (['/roles', '/roles/create', "/roles/{$role->id}", "/roles/{$role->id}/edit", '/users', '/users/create', "/users/{$other->id}/edit", '/units', '/hris-mappings', '/jotform-import', '/indicator-unit-mappings'] as $url) {
            $this->actingAs($picUnit)->get($url)->assertForbidden();
        }

        $this->actingAs($picUnit)->post('/roles', ['name' => 'X', 'permissions' => Permissions::all()])->assertForbidden();
        $this->actingAs($picUnit)->put("/roles/{$role->id}", ['name' => 'PIC Unit', 'permissions' => Permissions::all()])->assertForbidden();
        $this->actingAs($picUnit)->put("/users/{$picUnit->id}", ['role' => User::ROLE_ADMIN])->assertForbidden();

        $this->assertNotContains('users.view', $role->fresh()->permissionKeys());
        $this->assertSame(User::ROLE_PIC_UNIT, $picUnit->fresh()->role);
    }

    public function test_role_manager_cannot_grant_permissions_they_do_not_hold(): void
    {
        $managerRole = Role::create(['slug' => 'pengelola', 'name' => 'Pengelola Akses']);
        $managerRole->syncPermissions(['roles.view', 'roles.manage', 'users.view', 'users.create', 'users.edit', 'permissions.manage', 'units.view']);
        $manager = User::factory()->create(['role' => 'pengelola']);

        // New role: only permissions the manager holds.
        $this->actingAs($manager)->post('/roles', ['name' => 'Super', 'is_active' => '1', 'permissions' => ['units.view', 'units.delete']])
            ->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('roles', ['name' => 'Super']);

        // Existing role: adding a permission the manager lacks is refused.
        $mutu = Role::where('slug', 'pic_mutu')->firstOrFail();
        $this->actingAs($manager)->put("/roles/{$mutu->id}", ['name' => 'PIC Mutu', 'is_active' => '1', 'permissions' => [...$mutu->permissionKeys(), 'users.delete']])
            ->assertSessionHasErrors('permissions');
        $this->assertNotContains('users.delete', $mutu->fresh()->permissionKeys());

        // The system role is not theirs to give, nor are grants beyond their own.
        $target = User::factory()->picUnit($this->unit)->create(['nip' => '3003']);
        $payload = ['nip' => '3003', 'name' => $target->name, 'unit_id' => $this->unit->id];

        $this->actingAs($manager)->put("/users/{$target->id}", $payload + ['role' => User::ROLE_ADMIN])->assertSessionHasErrors('role');
        $this->actingAs($manager)->put("/users/{$target->id}", $payload + ['role' => User::ROLE_PIC_UNIT, 'overrides' => ['users.delete' => 'allow']])
            ->assertSessionHasErrors('overrides');
        $this->assertSame(User::ROLE_PIC_UNIT, $target->fresh()->role);
        $this->assertCount(0, $target->fresh()->permissionOverrides);

        // Accounts holding the system role are out of reach.
        $admin = User::factory()->admin()->create();
        $this->actingAs($manager)->get("/users/{$admin->id}/edit")->assertForbidden();
        $this->actingAs($manager)->patch("/users/{$admin->id}/status")->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_role_manager_cannot_lock_themselves_out(): void
    {
        $managerRole = Role::create(['slug' => 'pengelola', 'name' => 'Pengelola Akses']);
        $managerRole->syncPermissions(['roles.view', 'roles.manage']);
        $manager = User::factory()->create(['role' => 'pengelola']);

        $this->actingAs($manager)->put("/roles/{$managerRole->id}", ['name' => 'Pengelola Akses', 'is_active' => '1', 'permissions' => ['roles.view']])
            ->assertSessionHasErrors('permissions');
        $this->actingAs($manager)->patch("/roles/{$managerRole->id}/status")->assertSessionHas('error');

        $this->assertContains('roles.manage', $managerRole->fresh()->permissionKeys());
        $this->assertTrue($managerRole->fresh()->is_active);
    }

    public function test_access_management_pages_render_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::where('slug', 'pic_unit')->firstOrFail();

        $this->actingAs($admin)->get('/roles')->assertOk()->assertSee('Master Jabatan')->assertSee('Validator');
        $this->actingAs($admin)->get('/roles/create')->assertOk()->assertSee('name="permissions[]"', false);
        $this->actingAs($admin)->get("/roles/{$role->id}")->assertOk()->assertSee('observations.create');
        $this->actingAs($admin)->get("/roles/{$role->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('Manajemen Akses')->assertSee('Master Jabatan');
        $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('Izin Pengguna');
    }
}

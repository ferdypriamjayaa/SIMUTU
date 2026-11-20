<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\AccessGuard;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Master Jabatan: positions and the permissions they grant. Viewing needs
 * roles.view, every change roles.manage (route middleware). The system role
 * (Admin) is locked, and nobody grants a permission they do not hold.
 */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('name')->get(),
            'permissionTotal' => count(Permissions::all()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('roles.form', [
            'role' => new Role(['is_active' => true]),
            'granted' => [],
            'ungrantable' => AccessGuard::ungrantable($request->user(), Permissions::all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $this->ensureGrantable($request, $validated['permissions']);

        $role = Role::create([
            'slug' => $this->uniqueSlug($validated['name']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'is_system' => false,
        ]);
        $role->syncPermissions($validated['permissions']);

        return redirect()->route('roles.show', $role)->with('success', "Jabatan {$role->name} berhasil ditambahkan.");
    }

    public function show(Role $role): View
    {
        $role->load('permissions')->loadCount('users');

        return view('roles.show', [
            'role' => $role,
            'granted' => $role->is_system ? Permissions::all() : $role->permissionKeys(),
            'users' => $role->users()->with('unit')->orderBy('name')->limit(100)->get(),
        ]);
    }

    public function edit(Request $request, Role $role): View|RedirectResponse
    {
        if ($role->is_system) {
            return redirect()->route('roles.show', $role)->with('error', 'Jabatan sistem dikunci dan selalu memiliki semua izin.');
        }

        return view('roles.form', [
            'role' => $role,
            'granted' => $role->load('permissions')->permissionKeys(),
            'ungrantable' => AccessGuard::ungrantable($request->user(), Permissions::all()),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return redirect()->route('roles.show', $role)->with('error', 'Jabatan sistem dikunci dan selalu memiliki semua izin.');
        }

        $validated = $this->validateRole($request, $role);
        $current = $role->load('permissions')->permissionKeys();

        // Removing is always allowed; only newly added permissions must be held by the actor.
        $this->ensureGrantable($request, array_values(array_diff($validated['permissions'], $current)));

        $active = $request->boolean('is_active');
        $this->ensureNotLockingOut($request, $role, $validated['permissions'], $active);

        // The form has no description field any more; an existing description is kept.
        $role->update([
            'name' => $validated['name'],
            'is_active' => $active,
            ...($request->has('description') ? ['description' => $validated['description'] ?? null] : []),
        ]);
        $role->syncPermissions($validated['permissions']);

        return redirect()->route('roles.show', $role)->with('success', "Jabatan {$role->name} berhasil diperbarui.");
    }

    public function toggleStatus(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'Jabatan sistem tidak dapat dinonaktifkan.');
        }

        if ($role->is_active && $request->user()->role === $role->slug) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan jabatan Anda sendiri.');
        }

        $role->update(['is_active' => ! $role->is_active]);

        return back()->with('success', $role->is_active
            ? "Jabatan {$role->name} diaktifkan."
            : "Jabatan {$role->name} dinonaktifkan. Pengguna dengan jabatan ini kehilangan izin dari jabatan tersebut.");
    }

    /**
     * Only a role nobody holds can be deleted; accounts must be moved first.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'Jabatan sistem tidak dapat dihapus.');
        }

        $count = $role->users()->count();

        if ($count > 0) {
            return back()->with('error', "Jabatan {$role->name} masih dipakai oleh {$count} pengguna. Pindahkan pengguna tersebut ke jabatan lain terlebih dahulu, atau nonaktifkan jabatan ini.");
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', "Jabatan {$role->name} berhasil dihapus.");
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ], [], [
            'name' => 'Nama jabatan',
            'description' => 'Deskripsi',
            'permissions.*' => 'Izin',
        ]);

        $validated['permissions'] = array_values(array_unique($validated['permissions'] ?? []));

        return $validated;
    }

    /**
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    private function ensureGrantable(Request $request, array $permissions): void
    {
        $denied = AccessGuard::ungrantable($request->user(), $permissions);

        if ($denied !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Anda tidak dapat memberikan izin yang tidak Anda miliki: '.implode(', ', array_map(Permissions::label(...), $denied)).'.',
            ]);
        }
    }

    /**
     * Editing your own role must not take away your ability to manage roles.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    private function ensureNotLockingOut(Request $request, Role $role, array $permissions, bool $active): void
    {
        $actor = $request->user();

        if ($actor->role !== $role->slug) {
            return;
        }

        $after = Permissions::resolve(
            $permissions,
            $actor->permissionOverrides->pluck('is_granted', 'permission')->all(),
            false,
            $active,
        );

        if (! $after['roles.manage']['effective']) {
            throw ValidationException::withMessages([
                'permissions' => 'Perubahan ini akan menghapus akses Anda sendiri untuk mengelola jabatan. Pertahankan izin "'.Permissions::label('roles.manage').'" dan status aktif.',
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 24, '') ?: 'jabatan';
        $slug = $base;

        for ($i = 2; Role::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }
}

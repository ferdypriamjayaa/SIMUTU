<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\HrisException;
use App\Support\AccessGuard;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Account management. Each route requires its users.* permission; accounts
 * holding the system role (Admin) are managed by Admin only, and permission
 * overrides need permissions.manage (see AccessGuard).
 */
class UserController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): View
    {
        $units = Unit::orderBy('name')->get(['id', 'name', 'is_active']);
        $roles = Role::orderByDesc('is_system')->orderBy('name')->get(['slug', 'name', 'is_active']);

        // Unknown values in the address are ignored rather than showing an empty list.
        $search = trim((string) $request->query('search'));
        $role = $roles->contains('slug', (string) $request->query('role')) ? (string) $request->query('role') : '';
        $unit = $units->firstWhere('id', filter_var($request->query('unit_id'), FILTER_VALIDATE_INT));
        $sort = in_array($request->query('sort'), ['name', 'role', 'unit'], true) ? $request->query('sort') : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $query = User::with(['unit', 'assignedRole'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($role !== '', fn ($query) => $query->where('role', $role))
            ->when($unit, fn ($query) => $query->where('unit_id', $unit->id));

        match ($sort) {
            'role' => $query->orderBy(Role::select('name')->whereColumn('roles.slug', 'users.role'), $direction),
            // Accounts without a unit stay at the end in both directions.
            'unit' => $query->orderByRaw('unit_id is null')
                ->orderBy(Unit::select('name')->whereColumn('units.id', 'users.unit_id'), $direction),
            default => $query->orderBy('name', $direction),
        };

        if ($sort !== 'name') {
            $query->orderBy('name');
        }

        return view('users.index', [
            'users' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'role' => $role,
            'unit' => $unit,
            'sort' => $sort,
            'direction' => $direction,
            'units' => $units,
            'roles' => $roles,
            'filtered' => $search !== '' || $role !== '' || $unit !== null,
            'missingNipCount' => User::whereNull('nip')->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = new User(['role' => User::ROLE_PIC_UNIT, 'is_active' => true]);

        return view('users.form', [
            'user' => $user,
            'units' => $this->unitOptions(),
            'hris' => $this->hrisPicker(),
            ...$this->accessOptions($request->user(), $user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateUser($request);
        $this->ensureAccessAllowed($request, $validated);
        $hris = $this->hrisLink($request, $validated);

        $user = User::create([
            'nip' => $validated['nip'],
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'password' => $validated['password'],
            'role' => $validated['role'],
            'unit_id' => $validated['unit_id'] ?? null,
            'is_active' => true,
            ...$hris,
        ]);

        $this->saveOverrides($request, $user, $validated);

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun pengguna berhasil ditambahkan.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureCanManage($request, $user);

        return view('users.form', [
            'user' => $user,
            'units' => $this->unitOptions($user->unit_id),
            'hris' => $this->hrisPicker($user),
            ...$this->accessOptions($request->user(), $user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanManage($request, $user);
        $validated = $this->validateUser($request, $user);

        if ($user->is($request->user()) && $validated['role'] !== $user->role) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'Anda tidak dapat mengubah atau menurunkan jabatan akun Anda sendiri.']);
        }

        $this->ensureAccessAllowed($request, $validated, $user);

        $attributes = [
            'nip' => $validated['nip'],
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'role' => $validated['role'],
            'unit_id' => $validated['unit_id'] ?? null,
        ];

        $attributes += $this->hrisLink($request, $validated, $user);

        if (! empty($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $user->update($attributes);
        $this->saveOverrides($request, $user, $validated);

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun pengguna berhasil diperbarui.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanManage($request, $user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'Status akun berhasil diperbarui.');
    }

    /**
     * Observations reference their author, so accounts that recorded any are
     * kept for the history and should be deactivated instead.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureCanManage($request, $user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        $observationCount = $user->observations()->count();

        if ($observationCount > 0) {
            return back()->with('error', "Akun {$user->name} tidak dapat dihapus karena memiliki {$observationCount} data observasi yang harus tetap tersimpan. Nonaktifkan akun ini sebagai gantinya.");
        }

        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun pengguna berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUser(Request $request, ?User $user = null): array
    {
        // A typed NIP is digits only. A NIP taken from HRIS may also carry letters
        // (a few HRIS NIPs have a letter prefix); hrisLink() then requires it to
        // equal HRIS exactly, and falls back to digits only when HRIS has none.
        $fromHris = filled($request->input('hris_employee_id'));

        return $request->validate([
            'nip' => [
                'required',
                'string',
                'max:30',
                $fromHris ? 'regex:/^[A-Za-z0-9]+$/' : 'regex:/^[0-9]+$/',
                Rule::unique('users', 'nip')->ignore($user?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // Active roles, or the account's current role even if it was deactivated since.
            'role' => ['required', 'string', Rule::exists('roles', 'slug')->where(function ($query) use ($user) {
                $query->where('is_active', true)->when($user?->role, fn ($q, $role) => $q->orWhere('slug', $role));
            })],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['string', Rule::in(['inherit', 'allow', 'deny'])],
            'unit_id' => [
                Rule::requiredIf(fn () => $this->needsUnit($request, $user)),
                'nullable',
                'integer',
                // An existing assignment may stay even if the unit was later deactivated.
                Rule::exists('units', 'id')->where(function ($query) use ($user) {
                    $query->where('is_active', true)
                        ->when($user?->unit_id, fn ($q, $unitId) => $q->orWhere('id', $unitId));
                }),
            ],
            'password' => [
                $user ? 'nullable' : 'required',
                'confirmed',
                Password::defaults(),
            ],
        ], [
            'nip.regex' => $fromHris ? 'NIP hanya boleh berisi huruf dan angka.' : 'NIP hanya boleh berisi angka.',
            'unit_id.required' => 'Unit wajib dipilih untuk jabatan yang hanya dapat melihat data unitnya sendiri (seperti PIC Unit).',
        ], [
            'nip' => 'NIP',
            'name' => 'Nama',
            'email' => 'Email',
            'role' => 'Jabatan',
            'unit_id' => 'Unit',
            'password' => 'Kata Sandi',
        ]);
    }

    /**
     * HRIS employee choices for the form: active employees of every
     * configured company, name and opaque identifier only (no NIP, no
     * placement). Null when HRIS is not configured, so the field is hidden
     * and an existing link is left untouched.
     *
     * @return array{employees: list<array{id: string, name: string}>, notice: ?string, linked: ?string, linkedMissing: bool}|null
     */
    private function hrisPicker(?User $user = null): ?array
    {
        if (! $this->employees->isConfigured()) {
            return null;
        }

        $directory = $this->employees->directory();
        $employees = array_map(fn (array $e) => ['id' => $e['id'], 'name' => $e['name']], $directory['employees']);
        $linked = $user?->hris_employee_id
            ? collect($employees)->firstWhere('id', $user->hris_employee_id)
            : null;

        return [
            'employees' => $employees,
            'notice' => $directory['errors'] === [] ? null : implode(' ', $directory['errors']),
            'linked' => $linked['name'] ?? null,
            // Linked, but not (or no longer) among the active employees HRIS returned.
            'linkedMissing' => $user?->hris_employee_id !== null && $linked === null,
            // Where the form fetches the chosen employee's name and HRIS NIP.
            'detailsUrl' => route('users.hris-employee', ['employee' => '__ID__']),
        ];
    }

    /**
     * Name and NIP of one HRIS employee, for filling the account form when an
     * administrator picks that employee. Admin-only route; the NIP is read
     * fresh from HRIS (field `nip`) and not cached.
     */
    public function hrisEmployee(string $employee): JsonResponse
    {
        if (! $this->employees->isConfigured()) {
            return response()->json(['message' => 'Integrasi HRIS belum dikonfigurasi.'], 503);
        }

        try {
            $details = $this->employees->details($employee);
        } catch (HrisException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        if ($details === null) {
            return response()->json(['message' => 'Karyawan tidak ditemukan di daftar karyawan aktif HRIS.'], 404);
        }

        return response()->json(['name' => $details['name'], 'nip' => $details['nip']])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Account attributes for the HRIS employee chosen in the form. Only the
     * link changes here; role, unit, email and password are never touched.
     * The submitted name and NIP must match the employee in HRIS: the name,
     * and the NIP (when HRIS has one). When the field was not
     * submitted (HRIS not configured) nothing changes.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function hrisLink(Request $request, array $validated, ?User $user = null): array
    {
        if (! $request->has('hris_employee_id')) {
            return [];
        }

        $id = $request->input('hris_employee_id');

        // Unlinking keeps the name and NIP as submitted; the form asks before clearing them.
        if (blank($id)) {
            return ['hris_employee_id' => null, 'hris_company_code' => null, 'hris_linked_at' => null];
        }

        // Unchanged link with an unchanged name and NIP: nothing to check, no HRIS call,
        // so existing accounts can still be edited while HRIS is down.
        if ($user !== null && $id === $user->hris_employee_id
            && $validated['name'] === $user->name && $validated['nip'] === $user->nip) {
            return [];
        }

        $failed = fn (string $message) => ValidationException::withMessages(['hris_employee_id' => $message]);

        if (! is_string($id) || ! preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw $failed('Karyawan HRIS yang dipilih tidak valid. Pilih ulang dari daftar.');
        }

        $owner = User::where('hris_employee_id', $id)->when($user, fn ($q) => $q->whereKeyNot($user->id))->first();

        if ($owner !== null) {
            throw $failed("Karyawan ini sudah terhubung dengan akun {$owner->name}. Lepaskan tautan pada akun tersebut terlebih dahulu.");
        }

        try {
            $employee = $this->employees->details($id);
        } catch (HrisException $exception) {
            throw $failed($exception->getMessage());
        }

        if ($employee === null) {
            throw $failed('Karyawan yang dipilih tidak ditemukan di daftar karyawan aktif HRIS. Pilih ulang dari daftar.');
        }

        $mismatch = [];

        if ($validated['name'] !== $employee['name']) {
            $mismatch['name'] = 'Nama harus sama dengan nama karyawan HRIS yang dipilih. Pilih ulang karyawan untuk mengisi otomatis.';
        }

        // Compared as strings, so every digit (and leading zero) counts.
        if ($employee['nip'] !== null && $validated['nip'] !== $employee['nip']) {
            $mismatch['nip'] = 'NIP harus sama dengan NIP karyawan HRIS yang dipilih. Pilih ulang karyawan untuk mengisi otomatis.';
        } elseif ($employee['nip'] === null && ! preg_match('/^[0-9]+$/', $validated['nip'])) {
            $mismatch['nip'] = 'NIP hanya boleh berisi angka.';
        }

        if ($mismatch !== []) {
            throw ValidationException::withMessages($mismatch);
        }

        $unchanged = $user !== null && $id === $user->hris_employee_id;

        return [
            'hris_employee_id' => $employee['id'],
            'hris_company_code' => $employee['company'],
            'hris_linked_at' => $unchanged ? $user->hris_linked_at : now(),
        ];
    }

    /**
     * Role choices and the permission-override editor for the account form.
     *
     * @return array<string, mixed>
     */
    private function accessOptions(User $actor, User $user): array
    {
        $roles = Role::with('permissions')
            ->where(fn ($q) => $q->where('is_active', true)->when($user->role, fn ($q, $slug) => $q->orWhere('slug', $slug)))
            ->orderByDesc('is_system')->orderBy('name')
            ->get();

        return [
            'roles' => $roles->map(fn (Role $role) => [
                'slug' => $role->slug,
                'name' => $role->name.($role->is_active ? '' : ' (nonaktif)'),
                'system' => $role->is_system,
                'active' => $role->is_active,
                'permissions' => $role->is_system ? Permissions::all() : $role->permissionKeys(),
                'assignable' => $role->slug === $user->role || AccessGuard::canAssignRole($actor, $role),
            ])->values()->all(),
            'overrides' => $user->exists ? $user->permissionOverrides->pluck('is_granted', 'permission')->all() : [],
            'canOverride' => $this->canOverride($actor, $user),
            'grantable' => array_values(array_diff(Permissions::all(), AccessGuard::ungrantable($actor, Permissions::all()))),
        ];
    }

    /**
     * Overrides are set by holders of permissions.manage, never on their own account.
     */
    private function canOverride(User $actor, User $user): bool
    {
        return $actor->hasPermission('permissions.manage') && ! ($user->exists && $user->is($actor));
    }

    private function ensureCanManage(Request $request, User $user): void
    {
        abort_unless(
            AccessGuard::canManageUser($request->user(), $user),
            403,
            'Akun dengan jabatan sistem hanya dapat dikelola oleh Admin.'
        );
    }

    /**
     * The chosen role and the submitted overrides must stay within what the
     * actor holds, so nobody can hand out more access than they have.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function ensureAccessAllowed(Request $request, array $validated, ?User $user = null): void
    {
        $actor = $request->user();

        if ($validated['role'] !== $user?->role) {
            $role = Role::with('permissions')->where('slug', $validated['role'])->firstOrFail();

            if (! AccessGuard::canAssignRole($actor, $role)) {
                throw ValidationException::withMessages(['role' => $role->is_system
                    ? 'Hanya Admin yang dapat memberikan jabatan sistem.'
                    : 'Anda tidak dapat memberikan jabatan yang izinnya melebihi izin Anda sendiri.']);
            }
        }

        if (! $this->canOverride($actor, $user ?? new User)) {
            return;
        }

        $current = $user?->permissionOverrides->pluck('is_granted', 'permission')->all() ?? [];
        $denied = [];

        foreach ($this->submittedOverrides($request) as $permission => $granted) {
            // A grant that is new must be one the actor holds; an existing one may stay.
            if ($granted && ($current[$permission] ?? null) !== true && ! AccessGuard::canGrant($actor, $permission)) {
                $denied[] = Permissions::label($permission);
            }
        }

        if ($denied !== []) {
            throw ValidationException::withMessages([
                'overrides' => 'Anda tidak dapat memberikan izin yang tidak Anda miliki: '.implode(', ', $denied).'.',
            ]);
        }
    }

    /**
     * Explicit overrides from the form: permission => true (allow) / false (deny).
     * Unknown permissions and "inherit" are dropped.
     *
     * @return array<string, bool>
     */
    private function submittedOverrides(Request $request): array
    {
        $result = [];

        foreach ((array) $request->input('overrides', []) as $permission => $value) {
            if (is_string($permission) && Permissions::exists($permission) && in_array($value, ['allow', 'deny'], true)) {
                $result[$permission] = $value === 'allow';
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function saveOverrides(Request $request, User $user, array $validated): void
    {
        if (! $this->canOverride($request->user(), $user) || ! $request->has('overrides')) {
            return;
        }

        $overrides = $this->submittedOverrides($request);

        $user->permissionOverrides()->whereNotIn('permission', array_keys($overrides))->delete();

        foreach ($overrides as $permission => $granted) {
            $user->permissionOverrides()->updateOrCreate(['permission' => $permission], ['is_granted' => $granted]);
        }

        $user->forgetPermissions();
    }

    /**
     * A unit is required when the resulting account only sees its own unit's data.
     */
    private function needsUnit(Request $request, ?User $user): bool
    {
        $role = Role::with('permissions')->where('slug', (string) $request->input('role'))->first();

        if ($role === null) {
            return false;
        }

        $overrides = $this->canOverride($request->user(), $user ?? new User) && $request->has('overrides')
            ? $this->submittedOverrides($request)
            : ($user?->permissionOverrides->pluck('is_granted', 'permission')->all() ?? []);

        $matrix = Permissions::resolve($role->permissionKeys(), $overrides, $role->is_system, $role->is_active);

        return ! $matrix[Permissions::VIEW_ALL_UNITS]['effective'];
    }

    private function unitOptions(?int $currentUnitId = null)
    {
        return Unit::where('is_active', true)
            ->when($currentUnitId, fn ($q) => $q->orWhere('id', $currentUnitId))
            ->orderBy('name')
            ->get();
    }
}

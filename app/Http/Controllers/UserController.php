<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\HrisException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Account management. Routes are restricted to administrators.
 */
class UserController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $users = User::with('unit')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'missingNipCount' => User::whereNull('nip')->count(),
        ]);
    }

    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['role' => User::ROLE_PIC_UNIT, 'is_active' => true]),
            'units' => $this->unitOptions(),
            'hris' => $this->hrisPicker(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateUser($request);
        $hris = $this->hrisLink($request, $validated);

        User::create([
            'nip' => $validated['nip'],
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'password' => $validated['password'],
            'role' => $validated['role'],
            'unit_id' => $validated['unit_id'] ?? null,
            'is_active' => true,
            ...$hris,
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'units' => $this->unitOptions($user->unit_id),
            'hris' => $this->hrisPicker($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateUser($request, $user);

        if ($user->is($request->user()) && $validated['role'] !== User::ROLE_ADMIN) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'Anda tidak dapat menurunkan peran akun Anda sendiri.']);
        }

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

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun pengguna berhasil diperbarui.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
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
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'unit_id' => [
                Rule::requiredIf($request->input('role') === User::ROLE_PIC_UNIT),
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
            'unit_id.required' => 'Unit wajib dipilih untuk akun PIC Unit.',
        ], [
            'nip' => 'NIP',
            'name' => 'Nama',
            'email' => 'Email',
            'role' => 'Peran',
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

    private function unitOptions(?int $currentUnitId = null)
    {
        return Unit::where('is_active', true)
            ->when($currentUnitId, fn ($q) => $q->orWhere('id', $currentUnitId))
            ->orderBy('name')
            ->get();
    }
}

@php($editing = $user->exists)

<x-app-layout :title="$editing ? 'Edit Pengguna' : 'Tambah Pengguna'"
    :subtitle="$editing ? $user->name : null" :back="route('users.index')">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-flash />

        {{-- With HRIS, picking an employee fills Name and NIP (userHrisPicker); the server checks them again. --}}
        @if ($hris)
            @php($hrisChosen = old('hris_employee_id', $editing ? $user->hris_employee_id : null))
            @php($hrisChosenName = collect($hris['employees'])->firstWhere('id', $hrisChosen)['name'] ?? null)
            @php($hrisKeep = $hrisChosen !== null && $hrisChosenName === null && $hrisChosen === $user->hris_employee_id)
        @endif
        <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="card card-body space-y-8"
            @if ($hris)
                x-data="userHrisPicker(@js([
                    'employees' => $hris['employees'],
                    'detailsUrl' => $hris['detailsUrl'],
                    'id' => $hrisChosen,
                    'linkedName' => $hrisChosenName ?? ($hrisKeep ? 'Karyawan tertaut (tidak ada di daftar aktif HRIS)' : null),
                    'name' => old('name', $user->name),
                    'nip' => old('nip', $user->nip),
                ]))"
            @endif>
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            @if ($hris)
                <fieldset class="space-y-5">
                    <legend class="mb-4 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                        <span class="h-4 w-1 rounded-full bg-brand-500"></span> Karyawan HRIS
                    </legend>

                    <x-form.field label="PIC Unit (Karyawan HRIS)" for="hris_employee_search" error="hris_employee_id" optional
                        hint="Memilih karyawan aktif HRIS (RS BJB & RS BRB) mengisi Nama dan NIP secara otomatis. Jabatan, unit, email, dan kata sandi tidak berubah.">
                        <div @click.outside="open = false" @keydown.escape="open = false" class="relative space-y-2">
                            <input type="hidden" name="hris_employee_id" :value="id" value="{{ $hrisChosen }}">

                            <div x-show="id !== ''" @if ($hrisChosen === null) x-cloak @endif
                                class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                                <span class="font-medium text-navy-900 dark:text-slate-100" x-text="linkedName">{{ $hrisChosenName }}</span>
                                <button type="button" @click="unlink()" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400">Lepas tautan</button>
                            </div>

                            <input type="search" id="hris_employee_search" x-model="query" @focus="open = true" @input="open = true" :disabled="busy"
                                placeholder="Cari nama karyawan…" autocomplete="off" role="combobox" :aria-expanded="open" aria-controls="hris_employee_list"
                                @class(['form-control', 'form-control-invalid' => $errors->has('hris_employee_id')])>

                            <ul id="hris_employee_list" role="listbox" x-show="open" x-cloak
                                class="absolute z-20 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                <template x-for="e in matches" :key="e.id">
                                    <li role="option" :aria-selected="e.id === id">
                                        <button type="button" @click="choose(e)" x-text="e.name"
                                            class="block w-full px-3 py-2 text-left hover:bg-slate-100 dark:hover:bg-slate-700"
                                            :class="e.id === id && 'font-semibold text-brand-700 dark:text-brand-300'"></button>
                                    </li>
                                </template>
                                <li x-show="matches.length === 0" class="px-3 py-2 text-slate-500">Tidak ada karyawan yang cocok.</li>
                                <li x-show="matches.length === 50" class="px-3 py-2 text-xs text-slate-500">Ketik nama untuk mempersempit hasil.</li>
                            </ul>

                            <p x-show="busy" x-cloak class="form-hint">Mengambil data karyawan dari HRIS…</p>
                            <p x-show="error" x-cloak x-text="error" class="form-hint text-red-600 dark:text-red-400"></p>

                            {{-- Unlinking never clears Name/NIP by itself; the admin has to confirm. --}}
                            <div x-show="askClear" x-cloak class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                                Tautan dilepas. Nama dan NIP tetap terisi dan dapat diubah manual.
                                <button type="button" @click="clearIdentity()" class="ml-1 font-medium underline">Kosongkan Nama &amp; NIP</button>
                                <button type="button" @click="askClear = false" class="ml-2 underline">Tetap simpan</button>
                            </div>
                        </div>

                        @if ($hris['notice'])
                            <p class="form-hint text-amber-700 dark:text-amber-400">{{ $hris['notice'] }}</p>
                        @endif
                        @if ($hris['linkedMissing'])
                            <p class="form-hint text-amber-700 dark:text-amber-400">Karyawan yang tertaut dengan akun ini tidak ada di daftar karyawan aktif HRIS saat ini. Tautan tetap disimpan sampai dilepas atau diganti.</p>
                        @endif
                    </x-form.field>
                </fieldset>
            @endif

            <fieldset class="space-y-5">
                <legend class="mb-4 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                    <span class="h-4 w-1 rounded-full bg-brand-500"></span> Identitas
                </legend>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.field label="NIP" for="nip" required hint="Digunakan untuk login. Diketik manual: angka saja. Dari HRIS: sama persis dengan NIP HRIS. Nol di depan tetap disimpan.">
                        @if ($hris)
                            <x-form.input name="nip" :value="$user->nip" class="font-mono read-only:bg-slate-50 dark:read-only:bg-slate-800/60" inputmode="numeric" maxlength="30" required
                                x-model="nip" x-bind:readonly="locked && nipFromHris" />
                            <p x-show="locked && nipFromHris" x-cloak class="form-hint">Diisi otomatis dari NIP karyawan HRIS.</p>
                            <p x-show="locked && ! nipFromHris" x-cloak class="form-hint text-amber-700 dark:text-amber-400">NIP karyawan ini belum diisi di HRIS. Ketik NIP manual (angka saja).</p>
                        @else
                            <x-form.input name="nip" :value="$user->nip" class="font-mono" inputmode="numeric" maxlength="30" required autofocus />
                        @endif
                    </x-form.field>

                    <x-form.field label="Nama" for="name" required>
                        @if ($hris)
                            <x-form.input name="name" :value="$user->name" class="read-only:bg-slate-50 dark:read-only:bg-slate-800/60" required
                                x-model="accountName" x-bind:readonly="locked" />
                            <p x-show="locked" x-cloak class="form-hint">Diisi otomatis dari nama karyawan HRIS.</p>
                        @else
                            <x-form.input name="name" :value="$user->name" required />
                        @endif
                    </x-form.field>

                    <x-form.field label="Email" for="email" optional class="sm:col-span-2">
                        <x-form.input type="email" name="email" :value="$user->email" />
                    </x-form.field>
                </div>
            </fieldset>

            @php($permissionState = collect(App\Support\Permissions::all())->mapWithKeys(fn ($p) => [$p => old("overrides.$p", array_key_exists($p, $overrides) ? ($overrides[$p] ? 'allow' : 'deny') : 'inherit')])->all())
            @php($overrideCount = count(array_filter($permissionState, fn ($v) => $v !== 'inherit')))
            {{-- Effective permissions are recomputed here as the role or overrides change; the server applies the same rules (App\Support\Permissions). --}}
            <fieldset class="space-y-5" x-data="permissionTree(@js([
                'mode' => 'user',
                'role' => old('role', $user->role),
                'roles' => collect($roles)->keyBy('slug'),
                'overrides' => $permissionState,
                'grantable' => $grantable,
                'editable' => $canOverride,
                'leaves' => App\Support\Permissions::leavesByNode(),
                'open' => array_fill_keys(array_column(App\Support\Permissions::TREE, 'key'), true),
            ]))">
                <legend class="mb-4 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                    <span class="h-4 w-1 rounded-full bg-brand-500"></span> Akses
                </legend>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.field label="Jabatan" for="role" required>
                        <x-form.select name="role" required x-model="role">
                            @foreach ($roles as $item)
                                <option value="{{ $item['slug'] }}" @selected(old('role', $user->role) === $item['slug']) @disabled(! $item['assignable'])>
                                    {{ $item['name'] }}{{ $item['assignable'] ? '' : ' (melebihi izin Anda)' }}
                                </option>
                            @endforeach
                        </x-form.select>
                    </x-form.field>

                    <x-form.field label="Unit" for="unit_id" hint="Wajib untuk jabatan yang hanya melihat data unitnya sendiri (seperti PIC Unit).">
                        <x-form.select name="unit_id">
                            <option value="">— Tanpa unit —</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}" @selected((string) old('unit_id', $user->unit_id) === (string) $unit->id)>
                                    {{ $unit->name }}{{ $unit->is_active ? '' : ' (nonaktif)' }}
                                </option>
                            @endforeach
                        </x-form.select>
                    </x-form.field>
                </div>

                @php($selectedRole = collect($roles)->firstWhere('slug', old('role', $user->role)))
                @php($initialOn = $selectedRole ? array_keys(array_filter(App\Support\Permissions::resolve($selectedRole['permissions'], array_map(fn ($v) => $v === 'allow', array_filter($permissionState, fn ($v) => $v !== 'inherit')), $selectedRole['system'], $selectedRole['active']), fn ($p) => $p['effective'])) : [])
                <details class="rounded-xl border border-gray-200 dark:border-slate-800" @if ($errors->has('overrides') || $errors->has('overrides.*') || $overrideCount > 0) open @endif>
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-4 py-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                        <x-icon name="key" class="h-4 w-4 text-brand-600 dark:text-brand-300" />
                        Izin Pengguna
                        <span class="font-normal text-gray-500 dark:text-slate-400">
                            · <span x-text="summary.effective">{{ count($initialOn) }}</span> izin efektif
                            · <span class="text-accent-700 dark:text-accent-300"><span x-text="summary.added"></span> ditambahkan</span>
                            · <span class="text-red-700 dark:text-red-300"><span x-text="summary.restricted"></span> dibatasi</span>
                        </span>
                    </summary>

                    <div class="space-y-3 border-t border-gray-100 px-4 py-4 dark:border-slate-800">
                        @unless ($canOverride)
                            <x-alert type="info">
                                {{ $user->exists && $user->is(Auth::user()) ? 'Izin khusus akun Anda sendiri tidak dapat diubah dari sini.' : 'Anda tidak memiliki izin untuk mengatur izin khusus pengguna. Daftar ini hanya untuk dilihat.' }}
                            </x-alert>
                        @endunless
                        <p x-show="current?.system" x-cloak class="form-hint text-amber-700 dark:text-amber-400">Jabatan sistem selalu memiliki semua izin; izin khusus tidak berlaku.</p>
                        <x-input-error :messages="$errors->get('overrides')" />
                        <x-input-error :messages="$errors->get('overrides.*')" />

                        <x-permission-tree mode="user" :editable="$canOverride" :on="$initialOn" :state="$permissionState" />
                    </div>
                </details>
            </fieldset>

            <fieldset class="space-y-5">
                <legend class="mb-4 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                    <span class="h-4 w-1 rounded-full bg-brand-500"></span> Kata Sandi
                </legend>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-form.field :label="$editing ? 'Kata Sandi Baru' : 'Kata Sandi'" for="password" :required="! $editing" :optional="$editing"
                        :hint="$editing ? 'Kosongkan jika tidak ingin mengubah kata sandi.' : null">
                        <x-form.input type="password" name="password" autocomplete="new-password" :required="! $editing" />
                    </x-form.field>

                    <x-form.field label="Konfirmasi Kata Sandi" for="password_confirmation" :required="! $editing">
                        <x-form.input type="password" name="password_confirmation" autocomplete="new-password" :required="! $editing" />
                    </x-form.field>
                </div>
            </fieldset>

            <x-form.actions :cancel="route('users.index')" />
        </form>
    </div>
</x-app-layout>

<x-app-layout title="Master Pengguna">
    <x-slot name="actions">
        <x-button :href="route('users.create')" icon="plus">Tambah Pengguna</x-button>
    </x-slot>

    <div class="space-y-6">
        <x-flash />

        @if ($missingNipCount > 0)
            <x-alert type="warning">
                {{ $missingNipCount }} akun belum memiliki NIP dan belum dapat login. Lengkapi NIP melalui tombol Edit.
            </x-alert>
        @endif

        @php
            $unitOptions = [['value' => 'all', 'label' => 'Semua Unit'],
                ...$units->map(fn ($item) => ['value' => (string) $item->id, 'label' => $item->name.($item->is_active ? '' : ' (nonaktif)')])->all()];
            $selectedUnit = $unit ? ['value' => (string) $unit->id, 'label' => $unit->name] : $unitOptions[0];
            $columns = ['Pengguna' => 'name', 'NIP' => null, 'Jabatan' => 'role', 'Unit' => 'unit', 'Status' => null];
        @endphp

        <x-card title="Daftar Pengguna" :description="$users->total().($filtered ? ' akun ditemukan' : ' akun terdaftar')" icon="users" :padded="false">
            <form method="GET" action="{{ route('users.index') }}" role="search"
                class="grid gap-3 border-b border-gray-100 p-5 dark:border-slate-800 sm:grid-cols-2 sm:px-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                <input type="hidden" name="sort" value="{{ $sort }}" />
                <input type="hidden" name="direction" value="{{ $direction }}" />

                <x-form.field label="Cari" for="search" class="sm:col-span-2 xl:col-span-1">
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                        <input type="search" id="search" name="search" value="{{ $search }}" placeholder="Nama, NIP, atau email" class="form-control ps-9" />
                    </div>
                </x-form.field>

                <x-form.field label="Jabatan" for="role">
                    <x-form.select name="role">
                        <option value="">Semua Jabatan</option>
                        @foreach (\App\Models\User::ROLES as $value => $label)
                            <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </x-form.field>

                <x-form.field label="Unit" for="unit_id">
                    <x-form.combobox name="unit_id" :options="$unitOptions" :selected="$selectedUnit"
                        search-placeholder="Cari unit…" empty-text="Tidak ada unit." />
                </x-form.field>

                <div class="flex gap-2 sm:col-span-2 xl:col-span-1 xl:self-end">
                    <x-button variant="navy" icon="filter" loading-text="Memuat…" class="flex-1 xl:flex-none">Terapkan</x-button>
                    @if ($filtered)
                        <x-button :href="route('users.index', ['sort' => $sort, 'direction' => $direction])" variant="secondary" class="flex-1 xl:flex-none">Reset</x-button>
                    @endif
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            @foreach ($columns as $heading => $column)
                                @php
                                    $active = $column !== null && $sort === $column;
                                    $next = $active && $direction === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <th @if ($active) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                    @if ($column)
                                        <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'direction' => $next, 'page' => null]) }}"
                                            title="Urutkan berdasarkan {{ $heading }}"
                                            @class(['inline-flex items-center gap-1 uppercase transition-colors hover:text-navy-900 dark:hover:text-slate-100', 'text-navy-900 dark:text-slate-100' => $active])>
                                            {{ $heading }}
                                            <x-icon :name="$active && $direction === 'desc' ? 'chevron-down' : 'chevron-up'"
                                                @class(['h-3.5 w-3.5', 'opacity-30' => ! $active]) />
                                        </a>
                                    @else
                                        {{ $heading }}
                                    @endif
                                </th>
                            @endforeach
                            <th class="text-right"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-navy-50 dark:bg-navy-400/15 text-xs font-semibold text-navy-700 dark:text-navy-200">
                                            {{ \Illuminate\Support\Str::of($user->name)->explode(' ')->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-medium text-navy-900 dark:text-slate-100">
                                                {{ $user->name }}
                                                @if ($user->isLinkedToHris())
                                                    <x-badge color="green">HRIS</x-badge>
                                                @endif
                                                @if ($user->is(Auth::user()))
                                                    <span class="text-xs font-normal text-gray-400 dark:text-slate-500">(Anda)</span>
                                                @endif
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-slate-400">{{ $user->email ?? '-' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap font-mono text-navy-800 dark:text-slate-200">
                                    @if ($user->nip)
                                        {{ $user->nip }}
                                    @else
                                        <x-badge color="amber">Belum ada NIP</x-badge>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :color="['admin' => 'brand', 'pic_mutu' => 'navy'][$user->role] ?? 'gray'">{{ $user->roleLabel() }}</x-badge>
                                </td>
                                <td class="min-w-[10rem]">
                                    {{ $user->unit?->name ?? '-' }}
                                    @if ($user->unit && ! $user->unit->is_active)
                                        <span class="block text-xs text-red-600 dark:text-red-400">unit nonaktif</span>
                                    @endif
                                </td>
                                <td>
                                    <x-badge :color="$user->is_active ? 'green' : 'gray'" dot>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('users.edit', $user) }}" class="btn-icon" title="Edit" aria-label="Edit {{ $user->name }}">
                                            <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                        </a>

                                        @unless ($user->is(Auth::user()))
                                            <form action="{{ route('users.toggle-status', $user) }}" method="POST" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-icon" title="{{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                                    aria-label="{{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $user->name }}">
                                                    <x-icon name="power" :class="$user->is_active ? 'h-[18px] w-[18px]' : 'h-[18px] w-[18px] text-accent-600 dark:text-accent-300'" />
                                                </button>
                                            </form>

                                            <x-delete-form :action="route('users.destroy', $user)"
                                                :confirm="'Yakin ingin menghapus akun '.$user->name.'? Tindakan ini tidak dapat dibatalkan.'" />
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state icon="users" title="Tidak ada pengguna." description="Coba ubah pencarian atau filter." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-gray-100 dark:border-slate-800 px-5 py-4 sm:px-6">
                    {{ $users->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>

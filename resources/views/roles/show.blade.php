<x-app-layout title="Detail Jabatan" :subtitle="$role->name" :back="route('roles.index')">
    @if (! $role->is_system && Auth::user()->can('roles.manage'))
        <x-slot name="actions">
            <x-button :href="route('roles.edit', $role)" icon="pencil">Edit Jabatan</x-button>
        </x-slot>
    @endif

    <div class="space-y-6">
        <x-flash />

        <x-card :title="$role->name" :description="$role->description" icon="shield">
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-gray-500 dark:text-slate-400">Status</dt>
                    <dd class="mt-1">
                        <x-badge :color="$role->is_active ? 'green' : 'gray'" dot>{{ $role->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge>
                        @if ($role->is_system)
                            <x-badge color="brand">Sistem</x-badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-slate-400">Pengguna</dt>
                    <dd class="mt-1 font-semibold text-navy-900 dark:text-slate-100">{{ $role->users_count }} pengguna</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-slate-400">Izin</dt>
                    <dd class="mt-1 font-semibold text-navy-900 dark:text-slate-100">{{ count($granted) }} dari {{ count(App\Support\Permissions::all()) }}</dd>
                </div>
            </dl>

            @if ($role->is_system)
                <x-alert type="info" class="mt-5">Jabatan sistem dikunci: selalu memiliki semua izin, tidak dapat diubah, dinonaktifkan, atau dihapus.</x-alert>
            @elseif (! $role->is_active)
                <x-alert type="warning" class="mt-5">Jabatan ini nonaktif, sehingga tidak memberikan izin apa pun kepada penggunanya. Izin khusus per pengguna tetap berlaku.</x-alert>
            @endif
        </x-card>

        <x-card title="Izin Jabatan" icon="key">
            <div x-data="permissionTree(@js([
                'mode' => 'role',
                'checked' => array_values($granted),
                'editable' => false,
                'leaves' => App\Support\Permissions::leavesByNode(),
                'open' => array_fill_keys(array_column(App\Support\Permissions::TREE, 'key'), true),
            ]))">
                <x-permission-tree mode="role" :editable="false" :on="$granted" />
            </div>
        </x-card>

        <x-card title="Pengguna dengan Jabatan Ini" :description="$role->users_count > $users->count() ? 'Menampilkan '.$users->count().' dari '.$role->users_count.' pengguna' : null" icon="users" :padded="false">
            @if ($users->isEmpty())
                <x-empty-state icon="users" title="Belum ada pengguna dengan jabatan ini." />
            @else
                <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                    @foreach ($users as $user)
                        <li class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm sm:px-6">
                            <span class="min-w-0 flex-1 font-medium text-navy-900 dark:text-slate-100">{{ $user->name }}</span>
                            <span class="text-gray-500 dark:text-slate-400">{{ $user->unit?->name ?? 'Tanpa unit' }}</span>
                            <x-badge :color="$user->is_active ? 'green' : 'gray'" dot>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge>
                            @can('users.edit')
                                <a href="{{ route('users.edit', $user) }}" class="btn-icon" title="Edit pengguna" aria-label="Edit {{ $user->name }}">
                                    <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                </a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>

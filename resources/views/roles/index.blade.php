<x-app-layout title="Master Jabatan">
    @can('roles.manage')
        <x-slot name="actions">
            <x-button :href="route('roles.create')" icon="plus">Tambah Jabatan</x-button>
        </x-slot>
    @endcan

    <div class="space-y-6">
        <x-flash />

        <x-card title="Daftar Jabatan" :description="$roles->count().' jabatan terdaftar'" icon="shield" :padded="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Jabatan</th>
                            <th>Pengguna</th>
                            <th>Izin</th>
                            <th>Status</th>
                            <th class="text-right"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roles as $role)
                            <tr>
                                <td class="min-w-[16rem]">
                                    <p class="font-medium text-navy-900 dark:text-slate-100">
                                        <a href="{{ route('roles.show', $role) }}" class="hover:underline">{{ $role->name }}</a>
                                        @if ($role->is_system)
                                            <x-badge color="brand">Sistem</x-badge>
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400">{{ $role->description ?: '-' }}</p>
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ $role->users_count }} pengguna</td>
                                <td class="whitespace-nowrap tabular-nums">
                                    {{ $role->is_system ? $permissionTotal : $role->permissions_count }} dari {{ $permissionTotal }}
                                </td>
                                <td>
                                    <x-badge :color="$role->is_active ? 'green' : 'gray'" dot>{{ $role->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('roles.show', $role) }}" class="btn-icon" title="Lihat" aria-label="Lihat {{ $role->name }}">
                                            <x-icon name="eye" class="h-[18px] w-[18px]" />
                                        </a>

                                        @if (! $role->is_system && Auth::user()->can('roles.manage'))
                                            <a href="{{ route('roles.edit', $role) }}" class="btn-icon" title="Edit" aria-label="Edit {{ $role->name }}">
                                                <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                            </a>

                                            <form action="{{ route('roles.toggle-status', $role) }}" method="POST" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-icon" title="{{ $role->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                                    aria-label="{{ $role->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $role->name }}">
                                                    <x-icon name="power" :class="$role->is_active ? 'h-[18px] w-[18px]' : 'h-[18px] w-[18px] text-accent-600 dark:text-accent-300'" />
                                                </button>
                                            </form>

                                            <x-delete-form :action="route('roles.destroy', $role)"
                                                :confirm="'Yakin ingin menghapus jabatan '.$role->name.'? Jabatan yang masih dipakai pengguna tidak dapat dihapus.'" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-empty-state icon="shield" title="Belum ada jabatan." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>

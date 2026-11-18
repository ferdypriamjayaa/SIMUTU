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

        <x-card title="Daftar Pengguna" :description="$users->total().' akun terdaftar'" icon="users" :padded="false">
            <x-slot name="actions">
                <form method="GET" action="{{ route('users.index') }}" class="flex w-full gap-2 sm:w-auto" role="search">
                    <div class="relative flex-1 sm:w-72">
                        <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                        <input type="search" name="search" value="{{ $search }}" placeholder="Cari nama, NIP, atau email"
                            aria-label="Cari pengguna" class="form-control form-control-sm ps-9" />
                    </div>
                    <x-button variant="navy" class="h-10" loading-text="Mencari…">Cari</x-button>
                </form>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            @foreach (['Pengguna', 'NIP', 'Peran', 'Unit', 'Status'] as $heading)
                                <th>{{ $heading }}</th>
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
                                    <x-empty-state icon="users" title="Tidak ada pengguna." description="Coba kata kunci pencarian lain." />
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

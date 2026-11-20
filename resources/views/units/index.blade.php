<x-app-layout title="Master Unit">
    <div class="space-y-6">
        <x-flash />

        @can('units.create')
        <x-card title="Tambah Unit" icon="plus-circle">
            <form action="{{ route('units.store') }}" method="POST"
                class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] sm:items-end">
                @csrf

                <x-form.field label="Kode unit" for="code" required>
                    <x-form.input name="code" maxlength="50" class="uppercase placeholder:normal-case" placeholder="mis. IGD" required />
                </x-form.field>

                <x-form.field label="Nama unit" for="name" required>
                    <x-form.input name="name" maxlength="255" placeholder="mis. Instalasi Gawat Darurat" required />
                </x-form.field>

                <x-button icon="plus" loading-text="Menyimpan…">Tambah Unit</x-button>
            </form>
        </x-card>
        @endcan

        <x-card title="Daftar Unit" :description="$units->count().' unit terdaftar'" icon="building" :padded="false">
            <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                @forelse ($units as $unit)
                    <li x-data="{ editing: false }" class="px-5 py-4 sm:px-6">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-lg bg-navy-50 dark:bg-navy-400/15 px-2.5 py-1 font-mono text-xs font-semibold text-navy-700 dark:text-navy-200">{{ $unit->code }}</span>
                            <p class="min-w-0 flex-1 font-medium text-navy-900 dark:text-slate-100">{{ $unit->name }}</p>

                            <x-badge :color="$unit->is_active ? 'green' : 'gray'" dot>{{ $unit->is_active ? 'Aktif' : 'Tidak Aktif' }}</x-badge>

                            <div class="flex items-center gap-1">
                                @can('units.edit')
                                <button type="button" @click="editing = ! editing" class="btn-icon" title="Ubah kode/nama" aria-label="Ubah {{ $unit->name }}"
                                    :class="editing && 'bg-gray-100 dark:bg-slate-800 text-navy-900 dark:text-slate-100'">
                                    <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                </button>

                                <form action="{{ route('units.toggle-status', ['unit' => $unit->id]) }}" method="POST" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-icon" title="{{ $unit->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                        aria-label="{{ $unit->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $unit->name }}">
                                        <x-icon name="power" :class="$unit->is_active ? 'h-[18px] w-[18px]' : 'h-[18px] w-[18px] text-accent-600 dark:text-accent-300'" />
                                    </button>
                                </form>
                                @endcan

                                @can('units.delete')
                                <x-delete-form :action="route('units.destroy', ['unit' => $unit->id])"
                                    :confirm="'Yakin ingin menghapus unit '.$unit->name.'? Tindakan ini tidak dapat dibatalkan.'" />
                                @endcan
                            </div>
                        </div>

                        {{-- Inline edit --}}
                        @can('units.edit')
                        <form x-show="editing" x-cloak x-transition.opacity action="{{ route('units.update', ['unit' => $unit->id]) }}" method="POST"
                            class="mt-3 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 dark:border-slate-800 bg-gray-50/70 dark:bg-slate-800/40 p-3 sm:grid-cols-[8rem_minmax(0,1fr)_auto_auto]">
                            @csrf
                            @method('PUT')

                            <label for="code-{{ $unit->id }}" class="sr-only">Kode</label>
                            <input id="code-{{ $unit->id }}" name="code" value="{{ $unit->code }}" maxlength="50" required
                                class="form-control form-control-sm uppercase">

                            <label for="name-{{ $unit->id }}" class="sr-only">Nama</label>
                            <input id="name-{{ $unit->id }}" name="name" value="{{ $unit->name }}" maxlength="255" required
                                class="form-control form-control-sm">

                            <x-button variant="secondary" type="button" class="h-10" x-on:click="editing = false">Batal</x-button>
                            <x-button class="h-10" loading-text="Menyimpan…">Simpan</x-button>
                        </form>
                        @endcan
                    </li>
                @empty
                    <li>
                        <x-empty-state icon="building" title="Belum ada unit yang terdaftar." description="Tambahkan unit melalui formulir di atas." />
                    </li>
                @endforelse
            </ul>
        </x-card>
    </div>
</x-app-layout>

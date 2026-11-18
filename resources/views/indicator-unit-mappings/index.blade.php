@php
    $categoryLabels = ['inm' => 'INM', 'imp-rs' => 'IMP RS', 'imp-unit' => 'IMP Unit'];
    $categoryColors = ['INM' => 'brand', 'IMP_RS' => 'navy', 'IMP_UNIT' => 'green'];
    $hasFilters = $filters !== [];
@endphp

<x-app-layout title="Mapping Indikator & Unit">
    <div class="space-y-6">
        <x-flash />

        <div class="grid grid-cols-3 gap-3 sm:gap-4">
            <x-stat-card label="Total indikator" :value="$totals['all']" icon="chart" tone="navy" />
            <x-stat-card label="Sudah dipetakan" :value="$totals['mapped']" icon="check-circle" tone="green" />
            <x-stat-card label="Belum dipetakan" :value="$totals['unmapped']" icon="warning" tone="amber" />
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('indicator-unit-mappings.index') }}" class="card p-4 sm:p-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end">
                <x-form.field label="Cari indikator" for="q" class="sm:col-span-2 lg:col-span-1">
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                        <x-form.input type="search" name="q" :value="$filters['q'] ?? ''" class="ps-9" placeholder="Judul indikator…" maxlength="255" />
                    </div>
                </x-form.field>

                <x-form.field label="Kategori" for="category">
                    <x-form.select name="category">
                        <option value="">Semua kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>
                                {{ $categoryLabels[$category->slug] ?? $category->name }}
                            </option>
                        @endforeach
                    </x-form.select>
                </x-form.field>

                <x-form.field label="Status mapping" for="status">
                    <x-form.select name="status">
                        <option value="">Semua status</option>
                        <option value="mapped" @selected(($filters['status'] ?? '') === 'mapped')>Sudah dipetakan</option>
                        <option value="unmapped" @selected(($filters['status'] ?? '') === 'unmapped')>Belum dipetakan</option>
                    </x-form.select>
                </x-form.field>

                <div class="flex gap-2">
                    <x-button variant="navy" icon="filter" loading-text="Memuat…">Terapkan</x-button>
                    @if ($hasFilters)
                        <x-button variant="ghost" :href="route('indicator-unit-mappings.index')" icon="reset">Reset</x-button>
                    @endif
                </div>
            </div>
        </form>

        <x-card :padded="false">
            <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                @forelse ($indicators as $indicator)
                    @php
                        $code = $indicator->category?->code;
                        $isInm = $code === 'INM';
                        $count = $indicator->assignedUnits->count();
                    @endphp
                    <li class="px-5 py-4 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-navy-900 dark:text-slate-100">{{ $indicator->name }}</p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <x-badge :color="$categoryColors[$code] ?? 'gray'">{{ $categoryLabels[$indicator->category?->slug] ?? '-' }}</x-badge>
                                    @if ($count > 0)
                                        <x-badge color="green" dot>Dipetakan ke {{ $count }} unit</x-badge>
                                    @else
                                        <x-badge color="amber" dot>Belum dipetakan</x-badge>
                                    @endif
                                    @unless ($indicator->is_active)
                                        <x-badge color="gray">Indikator nonaktif</x-badge>
                                    @endunless
                                    @if ($indicator->unit)
                                        <x-badge color="gray">Penanggung jawab: {{ $indicator->unit->name }}</x-badge>
                                    @endif
                                </div>
                            </div>

                            <x-button variant="secondary" size="sm" icon="pencil"
                                :href="route('indicator-unit-mappings.edit', [$indicator, ...request()->query()])">
                                {{ $count > 0 ? 'Ubah Unit' : 'Tambah Unit' }}
                            </x-button>
                        </div>

                        @if ($count > 0)
                            <ul class="mt-3 flex flex-wrap gap-2" aria-label="Unit yang dipetakan">
                                @foreach ($indicator->assignedUnits as $unit)
                                    <li class="inline-flex items-center gap-1 rounded-full bg-navy-50 py-0.5 pe-1 ps-3 text-xs font-medium text-navy-700 ring-1 ring-inset ring-navy-600/20 dark:bg-navy-400/15 dark:text-navy-200 dark:ring-navy-300/30">
                                        {{ $unit->name }}
                                        @unless ($unit->is_active)
                                            <span class="font-normal text-gray-500 dark:text-slate-400">(nonaktif)</span>
                                        @endunless
                                        <form method="POST" action="{{ route('indicator-unit-mappings.destroy', [$indicator, $unit]) }}" class="inline-flex"
                                            onsubmit="return confirm(@js('Hapus unit '.$unit->name.' dari mapping indikator ini?'.($isInm ? ' Ini indikator INM: unit tersebut tidak akan dapat lagi menginput observasi indikator ini.' : '')))">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="flex h-6 w-6 items-center justify-center rounded-full text-navy-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40 dark:text-navy-300 dark:hover:bg-red-500/15 dark:hover:text-red-400"
                                                title="Hapus {{ $unit->name }}" aria-label="Hapus unit {{ $unit->name }} dari indikator {{ $indicator->name }}">
                                                <x-icon name="x" class="h-3.5 w-3.5" />
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @empty
                    <li>
                        <x-empty-state icon="chart"
                            :title="$hasFilters ? 'Tidak ada indikator yang cocok dengan filter ini.' : 'Belum ada indikator.'"
                            :description="$hasFilters ? 'Ubah kata kunci, kategori, atau status mapping.' : null" />
                    </li>
                @endforelse
            </ul>

            @if ($indicators->hasPages())
                <div class="border-t border-gray-100 px-5 py-4 dark:border-slate-800 sm:px-6">
                    {{ $indicators->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>

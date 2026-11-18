@php($manageable = in_array($category->code, ['IMP_RS', 'IMP_UNIT']) && Auth::user()->isAdmin())
@php($isAdmin = Auth::user()->isAdmin())

<x-app-layout :title="$categoryName">
    @if ($manageable)
        <x-slot name="actions">
            <x-button :href="route('indicators.create', ['category' => $category->slug])" icon="plus">Tambah Indikator</x-button>
        </x-slot>
    @endif

    <div class="space-y-6">
        <x-flash />

        {{-- Category tabs --}}
        <nav class="flex gap-1 overflow-x-auto rounded-xl bg-white dark:bg-slate-900 p-1 shadow-card ring-1 ring-gray-200/80 dark:ring-slate-800 sm:inline-flex" aria-label="Kategori indikator">
            @foreach (['inm' => 'INM', 'imp-rs' => 'IMP RS', 'imp-unit' => 'IMP Unit'] as $slug => $label)
                <a href="{{ route('indicators.show', ['category' => $slug]) }}" @if ($category->slug === $slug) aria-current="page" @endif
                    @class([
                        'whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition',
                        'bg-navy-800 dark:bg-slate-700 text-white shadow-sm' => $category->slug === $slug,
                        'text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800/60 hover:text-navy-900 dark:hover:text-slate-100' => $category->slug !== $slug,
                    ])>{{ $label }}</a>
            @endforeach
        </nav>

        <x-card :padded="false">
            <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                @forelse ($indicators as $indicator)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 transition hover:bg-brand-50/30 dark:hover:bg-brand-500/10 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-500/15 text-brand-600 dark:text-brand-300">
                                <x-icon name="chart" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium text-navy-900 dark:text-slate-100">{{ $indicator->name }}</p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <x-badge :color="$indicator->is_active ? 'green' : 'gray'" dot>{{ $indicator->is_active ? 'Aktif' : 'Tidak Aktif' }}</x-badge>
                                    @if ($indicator->unit)
                                        <x-badge color="navy">Unit: {{ $indicator->unit->name }}</x-badge>
                                    @endif
                                    @if ($indicator->publishedFormVersion)
                                        <x-badge color="brand">Formulir v{{ $indicator->publishedFormVersion->version }}</x-badge>
                                    @else
                                        <x-badge color="gray">Belum ada formulir terbit</x-badge>
                                    @endif
                                    @if ($isAdmin && $indicator->draftFormVersion)
                                        <x-badge color="amber">Draf v{{ $indicator->draftFormVersion->version }}</x-badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($isAdmin)
                            <div class="flex items-center gap-1">
                                <x-button variant="secondary" size="sm" icon="clipboard" :href="route('form-builder.show', $indicator)">Formulir</x-button>
                        @endif

                        @if ($manageable)
                                <a href="{{ route('indicators.edit', ['category' => $category->slug, 'indicator' => $indicator->id]) }}"
                                    class="btn-icon" title="Edit" aria-label="Edit">
                                    <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                </a>

                                <form action="{{ route('indicators.toggle-status', ['category' => $category->slug, 'indicator' => $indicator->id]) }}"
                                    method="POST" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-icon" title="{{ $indicator->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                        aria-label="{{ $indicator->is_active ? 'Nonaktifkan' : 'Aktifkan' }}">
                                        <x-icon name="power" :class="$indicator->is_active ? 'h-[18px] w-[18px]' : 'h-[18px] w-[18px] text-accent-600 dark:text-accent-300'" />
                                    </button>
                                </form>

                                <x-delete-form :action="route('indicators.destroy', ['category' => $category->slug, 'indicator' => $indicator->id])"
                                    confirm="Yakin ingin menghapus indikator ini?" />
                        @endif

                        @if ($isAdmin)
                            </div>
                        @endif
                    </li>
                @empty
                    <li>
                        <x-empty-state icon="chart" title="Belum ada indikator pada kategori ini." />
                    </li>
                @endforelse
            </ul>
        </x-card>
    </div>
</x-app-layout>

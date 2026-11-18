<x-app-layout title="Form Observasi Mutu">
    <x-slot name="actions">
        <x-button variant="secondary" :href="route('observations.index')" icon="clipboard">Lihat Laporan</x-button>
    </x-slot>

    @php
        // The category tab travels with every link and form, so the user lands back on it.
        $tabQuery = $category === 'semua' ? [] : ['kategori' => $category];
        $closeUrl = route('observations.create', $tabQuery);
        $categoryLabel = fn ($option) => str_replace('_', ' ', $option->category?->code ?? '');
        $emptyLabels = ['semua' => 'Belum ada indikator yang ditetapkan untuk unit Anda.', 'inm' => 'Belum ada indikator INM yang ditetapkan untuk unit Anda.', 'imp-rs' => 'Belum ada indikator IMP RS yang ditetapkan untuk unit Anda.', 'imp-unit' => 'Belum ada indikator IMP Unit yang ditetapkan untuk unit Anda.'];
    @endphp

    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-300">
                <x-icon name="building" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                Unit: <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $unit?->name }}</span>
            </div>

            {{-- Category filter --}}
            <nav aria-label="Filter kategori indikator" class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <ul class="inline-flex min-w-max gap-1 rounded-xl bg-gray-100 dark:bg-slate-800/70 p-1">
                    @foreach ($categoryTabs as $slug => $tab)
                        @php($active = $slug === $category)
                        <li>
                            <a href="{{ route('observations.create', $slug === 'semua' ? [] : ['kategori' => $slug]) }}"
                                @if ($active) aria-current="page" @endif
                                data-category-tab="{{ $slug }}"
                                @class([
                                    'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                    'bg-white dark:bg-slate-900 text-navy-900 dark:text-slate-100 shadow-sm' => $active,
                                    'text-gray-600 dark:text-slate-400 hover:text-navy-900 dark:hover:text-slate-200' => ! $active,
                                ])>
                                {{ $tab['label'] }}
                                <span @class([
                                    'rounded-full px-1.5 text-xs tabular-nums',
                                    'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300' => $active,
                                    'bg-gray-200/70 dark:bg-slate-700 text-gray-500 dark:text-slate-400' => ! $active,
                                ])>{{ $tab['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>

        {{-- Indicator cards --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($indicators as $option)
                @php($selected = $indicator?->is($option))
                @if ($option->input_status === 'ready')
                    <a href="{{ route('observations.create.indicator', [$option, ...$tabQuery]) }}"
                        data-indicator-card="{{ $option->id }}"
                        @if ($selected) aria-current="true" @endif
                        @class([
                            'group relative flex items-start gap-4 rounded-2xl border bg-white dark:bg-slate-900 p-4 transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/30',
                            'border-brand-500 shadow-raised ring-4 ring-brand-500/10' => $selected,
                            'border-gray-200 dark:border-slate-800 shadow-card hover:border-brand-300 dark:hover:border-brand-500/50 hover:shadow-raised' => ! $selected,
                        ])>
                        <span @class([
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition',
                            'bg-brand-600 text-white' => $selected,
                            'bg-brand-50 dark:bg-brand-500/15 text-brand-600 dark:text-brand-300 group-hover:bg-brand-100 dark:group-hover:bg-brand-500/25' => ! $selected,
                        ])>
                            <x-icon :name="$selected ? 'check-circle' : 'clipboard'" class="h-6 w-6" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ $categoryLabel($option) }}</span>
                            <span class="mt-0.5 block text-sm font-semibold text-navy-900 dark:text-slate-100">{{ $option->name }}</span>
                            <span class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-300">
                                {{ $selected ? 'Sedang diisi' : 'Isi observasi' }}
                                <x-icon name="plus" class="h-3.5 w-3.5" />
                            </span>
                        </span>
                    </a>
                @else
                    {{-- Mapped, but nothing to fill in: shown with its status, not selectable. --}}
                    <div data-indicator-card="{{ $option->id }}" aria-disabled="true"
                        class="flex items-start gap-4 rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-900/50 p-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-500">
                            <x-icon name="clipboard" class="h-6 w-6" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{{ $categoryLabel($option) }}</span>
                            <span class="mt-0.5 block text-sm font-semibold text-gray-600 dark:text-slate-300">{{ $option->name }}</span>
                            @if ($option->input_status === 'unpublished')
                                <x-badge color="amber" dot class="mt-1.5" title="Formulir indikator ini belum diterbitkan administrator, sehingga belum dapat diinput.">Formulir belum terbit</x-badge>
                            @else
                                <x-badge dot class="mt-1.5" title="Indikator IMP Unit belum dibuka untuk input observasi oleh PIC Unit.">Belum dibuka untuk input</x-badge>
                            @endif
                        </span>
                    </div>
                @endif
            @empty
                <x-alert type="warning" class="sm:col-span-2 lg:col-span-3">
                    {{ $emptyLabels[$category] }} Hubungi administrator.
                </x-alert>
            @endforelse
        </div>
    </div>

    {{--
        Observation form in a modal. Opening an indicator loads this page with
        the modal open, so validation errors and the user's answers come back
        in it after a failed save. A successful save returns to the list.
    --}}
    @if ($indicator)
        {{-- Moved to <body>: the page content is its own stacking context (view transitions). --}}
        <template x-teleport="body">
        <div x-data="dialog(@js(['closeUrl' => $closeUrl, 'dirty' => $errors->any()]))"
            x-show="visible" x-cloak
            x-on:keydown.escape.window="close()"
            x-on:keydown.tab="trap($event)"
            id="observation-modal"
            role="dialog" aria-modal="true" aria-labelledby="observation-modal-title"
            class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6">
            <div x-show="open" x-on:click="closeFromBackdrop()" aria-hidden="true"
                x-transition:enter="transition-opacity ease-out duration-200 motion-reduce:transition-none"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-200 motion-reduce:transition-none"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-gray-900/60 dark:bg-slate-950/80"></div>

            {{-- Mobile: slides up from the bottom. Larger screens: fades and scales in, centred. --}}
            <form action="{{ route('observations.store') }}" method="POST" data-keep-scroll
                x-show="open"
                x-on:input="dirty = true" x-on:change="dirty = true"
                x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
                x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-0 sm:scale-[0.97]"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-150 motion-reduce:transition-none"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-6 sm:translate-y-0 sm:scale-[0.97]"
                class="relative flex h-[96dvh] w-full max-w-3xl transform-gpu flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl will-change-transform dark:bg-slate-900 sm:h-auto sm:max-h-[88vh] sm:rounded-2xl">
                @csrf
                <input type="hidden" name="indicator_id" value="{{ $indicator->id }}" />
                <input type="hidden" name="form_version_id" value="{{ $version->id }}" />
                @if ($tabQuery)
                    <input type="hidden" name="kategori" value="{{ $category }}" />
                @endif

                <header class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 dark:border-slate-800 sm:gap-4 sm:px-6 sm:py-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">
                            Formulir Observasi · {{ str_replace('_', ' ', $indicator->category?->code ?? '') }}
                        </p>
                        <h2 id="observation-modal-title" class="mt-0.5 text-base font-bold leading-snug text-navy-900 dark:text-slate-100 sm:mt-1 sm:text-lg">{{ $indicator->name }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{{ $unit?->name }} · Formulir versi {{ $version->version }}</p>
                    </div>
                    <a href="{{ $closeUrl }}" x-on:click.prevent="close()" data-no-progress
                        class="-mr-1 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                        aria-label="Tutup formulir">
                        <x-icon name="x" class="h-5 w-5" />
                    </a>
                </header>

                {{-- Long forms scroll here; header and buttons stay in view. --}}
                <div data-dialog-body class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain px-4 py-4 sm:space-y-6 sm:px-6 sm:py-5">
                    <x-flash />

                    @include('observations.partials.fields', ['version' => $version, 'values' => $values, 'employees' => $employees, 'doctors' => $doctors])
                </div>

                {{-- Phones: Batal and a wide Simpan side by side, within thumb reach. --}}
                <footer class="flex items-center gap-2 border-t border-gray-100 bg-white px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-slate-800 dark:bg-slate-900 sm:justify-end sm:gap-3 sm:px-6 sm:py-4">
                    <a href="{{ $closeUrl }}" x-on:click.prevent="close()" data-no-progress class="btn btn-secondary shrink-0">Batal</a>
                    <x-button icon="check-circle" loading-text="Menyimpan…" class="flex-1 sm:flex-none">Simpan Observasi</x-button>
                </footer>
            </form>
        </div>
        </template>
    @endif

    {{--
        The list keeps its scroll position: opening a card, saving and coming
        back reload this page, so the position is carried over in the session.
        Runs inline, before the first paint, so the page does not jump.
    --}}
    <script>
        (() => {
            const KEY = 'simutu:observation-scroll';
            try {
                const saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
                sessionStorage.removeItem(KEY);
                if (saved && Date.now() - saved.at < 120000) {
                    history.scrollRestoration = 'manual';
                    window.scrollTo(0, saved.y);
                }
            } catch (e) {}
            const remember = () => {
                try { sessionStorage.setItem(KEY, JSON.stringify({ y: window.scrollY, at: Date.now() })); } catch (e) {}
            };
            document.addEventListener('click', (event) => {
                if (event.target.closest('[data-indicator-card][href], [data-category-tab]')) remember();
            });
            document.addEventListener('submit', (event) => {
                if (event.target.matches('[data-keep-scroll]')) remember();
            });
        })();
    </script>
</x-app-layout>

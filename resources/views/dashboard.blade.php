@php
    $user = Auth::user();
    $maxTrend = max(1, ...array_column($trend, 'total'));
    $historyQuery = array_filter($filters);
    $number = fn (int $value) => number_format($value, 0, ',', '.');

    $tabLabels = ['INM' => 'INM', 'IMP_RS' => 'IMP RS', 'IMP_UNIT' => 'IMP Unit'];
    $byCategory = $indicators->groupBy('indicator_category_id');
    $defaultTab = $categories->first(fn ($category) => $byCategory->has($category->id))?->code ?? $categories->first()?->code;
@endphp

<x-app-layout title="Dashboard Mutu">
    @can('create', App\Models\Observation::class)
        <x-slot name="actions">
            <x-button :href="route('observations.create')" icon="plus">Input Observasi</x-button>
        </x-slot>
    @endcan

    <div class="space-y-5">
        <x-flash />

        @if ($missingUnit)
            <x-alert type="warning">
                Akun Anda belum terhubung dengan unit yang aktif. Hubungi administrator agar dapat menginput dan melihat observasi.
            </x-alert>
        @endif

        {{-- Filters --}}
        <form method="GET" action="{{ route('dashboard') }}" class="card p-4 sm:p-5">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.4fr_auto] lg:items-end">
                <x-form.field label="Tanggal Mulai" for="start_date">
                    <x-form.input type="date" name="start_date" :value="$filters['start_date']" />
                </x-form.field>
                <x-form.field label="Tanggal Akhir" for="end_date">
                    <x-form.input type="date" name="end_date" :value="$filters['end_date']" />
                </x-form.field>
                @if ($units->isNotEmpty())
                    <x-form.field label="Unit" for="unit_id">
                        <x-form.select name="unit_id">
                            <option value="">Semua unit</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}" @selected((string) $filters['unit_id'] === (string) $unit->id)>{{ $unit->name }}</option>
                            @endforeach
                        </x-form.select>
                    </x-form.field>
                @endif
                <div @class(['flex gap-2', 'lg:col-start-4' => $units->isEmpty()])>
                    <x-button variant="navy" icon="filter" loading-text="Memuat…">Terapkan</x-button>
                    <x-button variant="ghost" :href="route('dashboard')" icon="reset">Reset</x-button>
                </div>
            </div>
        </form>

        {{-- Summary --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <x-stat-card label="Total Observasi" :value="$number($total)" hint="Pada periode terpilih" icon="clipboard" tone="brand" />
            <x-stat-card label="Total Indikator Aktif" :value="$number($activeIndicators)"
                :hint="$scoped ? 'Ditetapkan untuk unit ini' : 'INM, IMP RS, dan IMP Unit'" icon="chart" tone="navy" />
            <x-stat-card label="Total Unit" :value="$number($totalUnits)" :hint="$scoped ? 'Unit terpilih' : 'Unit aktif'" icon="building" tone="navy" />
            <x-stat-card label="Unit yang Sudah Melapor" :value="$number($reportingUnits)"
                :hint="'dari '.$number($totalUnits).' unit pada periode ini'" icon="check-circle" tone="green" />
        </div>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
            {{-- Indicators by category --}}
            <section class="card lg:col-span-2" x-data="{ tab: @js($defaultTab) }" aria-labelledby="indicator-summary-title">
                <header class="card-header">
                    <h3 id="indicator-summary-title" class="card-title min-w-0">Ringkasan Indikator Mutu</h3>

                    <div class="flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-slate-800" role="tablist" aria-label="Kategori indikator">
                        @foreach ($categories as $category)
                            <button type="button" role="tab" id="tab-{{ $category->slug }}" aria-controls="panel-{{ $category->slug }}"
                                x-on:click="tab = @js($category->code)" :aria-selected="tab === @js($category->code)"
                                class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                                :class="tab === @js($category->code)
                                    ? 'bg-white text-navy-900 shadow-sm dark:bg-slate-700 dark:text-slate-100'
                                    : 'text-gray-500 hover:text-navy-900 dark:text-slate-400 dark:hover:text-slate-100'">
                                {{ $tabLabels[$category->code] ?? $category->name }}
                                <span class="ms-1 font-normal tabular-nums opacity-70">{{ $byCategory->get($category->id, collect())->count() }}</span>
                            </button>
                        @endforeach
                    </div>
                </header>

                @foreach ($categories as $category)
                    <ul id="panel-{{ $category->slug }}" role="tabpanel" aria-labelledby="tab-{{ $category->slug }}"
                        x-show="tab === @js($category->code)" @if ($category->code !== $defaultTab) x-cloak @endif
                        class="divide-y divide-gray-100 dark:divide-slate-800">
                        @forelse ($byCategory->get($category->id, collect()) as $indicator)
                            <li class="flex items-center gap-4 px-5 py-3 sm:px-6">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-navy-900 dark:text-slate-100">{{ $indicator->name }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-slate-400">
                                        @if ($indicator->total > 0)
                                            <x-badge color="green" dot>Sudah ada laporan</x-badge>
                                        @elseif ($indicator->assigned)
                                            <x-badge color="amber" dot>Belum ada laporan</x-badge>
                                        @else
                                            <x-badge color="gray" dot>Belum dipetakan ke unit</x-badge>
                                        @endif

                                        @if (! $indicator->is_active)
                                            <x-badge>Data historis · indikator nonaktif</x-badge>
                                        @elseif ($scoped && ! $indicator->assigned)
                                            <x-badge>Data historis · tidak lagi ditetapkan</x-badge>
                                        @elseif (! $scoped && $indicator->assigned)
                                            <span>{{ $indicator->reporting_units_count }} dari {{ $indicator->assigned_units_count }} unit melapor</span>
                                        @elseif (! $scoped && $indicator->total > 0)
                                            <span>Belum dipetakan ke unit</span>
                                        @endif

                                        @if ($unpublishedIds->contains($indicator->id))
                                            <x-badge>Formulir belum terbit</x-badge>
                                        @endif
                                    </div>
                                </div>

                                @if ($inputIds->contains($indicator->id))
                                    <x-button size="sm" variant="secondary" icon="plus" class="shrink-0"
                                        :href="route('observations.create.indicator', $indicator)">Input Observasi</x-button>
                                @endif

                                <a href="{{ route('observations.index', [...$historyQuery, 'indicator_id' => $indicator->id]) }}"
                                    class="shrink-0 text-right transition hover:text-brand-700 dark:hover:text-brand-300" title="Lihat riwayat observasi indikator ini">
                                    <span class="block text-lg font-bold tabular-nums leading-none text-navy-900 dark:text-slate-100">{{ $number($indicator->total) }}</span>
                                    <span class="text-[11px] text-gray-500 dark:text-slate-400">observasi</span>
                                </a>
                            </li>
                        @empty
                            <li>
                                <x-empty-state icon="chart"
                                    :title="$scoped ? 'Belum ada indikator '.($tabLabels[$category->code] ?? '').' yang ditetapkan untuk unit ini.' : 'Belum ada indikator '.($tabLabels[$category->code] ?? '').' aktif.'" />
                            </li>
                        @endforelse
                    </ul>
                @endforeach

            </section>

            {{-- Monthly trend --}}
            <x-card title="Tren Observasi" description="6 bulan terakhir" icon="trend">
                <div class="flex h-36 items-end gap-2 sm:gap-3">
                    @foreach ($trend as $month)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                            <span class="text-xs font-semibold tabular-nums text-navy-900 dark:text-slate-100">{{ $month['total'] }}</span>
                            <div @class([
                                'w-full max-w-[2.25rem] rounded-t-md',
                                'bg-brand-500' => $loop->last,
                                'bg-navy-200 dark:bg-slate-700' => ! $loop->last,
                            ]) style="height: {{ max(3, round($month['total'] / $maxTrend * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-2 border-t border-gray-100 pt-2 dark:border-slate-800 sm:gap-3">
                    @foreach ($trend as $month)
                        <span class="flex-1 text-center text-[11px] text-gray-500 dark:text-slate-400">{{ $month['label'] }}</span>
                    @endforeach
                </div>
            </x-card>
        </div>

        {{-- Reporting per unit --}}
        <x-card title="Pelaporan Unit" icon="building" :padded="false">
            {{-- A list, not a table: on phones each unit stacks instead of scrolling sideways. --}}
            <div class="hidden grid-cols-[minmax(0,1fr)_6rem_6rem_11rem] gap-4 bg-gray-50/80 px-6 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-slate-800/50 dark:text-slate-400 sm:grid" aria-hidden="true">
                <span>Unit</span>
                <span class="text-right">Indikator</span>
                <span class="text-right">Observasi</span>
                <span>Status</span>
            </div>

            <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                @forelse ($unitRows as $row)
                    <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_6rem_6rem_11rem] sm:px-6">
                        <p class="text-sm font-medium text-navy-900 dark:text-slate-100">
                            {{ $row['unit']->name }}
                            @unless ($row['unit']->is_active)
                                <span class="font-normal text-gray-400 dark:text-slate-500">(nonaktif)</span>
                            @endunless
                        </p>

                        <p class="col-start-1 row-start-2 text-xs text-gray-500 dark:text-slate-400 sm:col-start-2 sm:row-start-1 sm:text-right sm:text-sm sm:tabular-nums sm:text-gray-700 sm:dark:text-slate-300">
                            {{ $row['indicators'] }}<span class="sm:sr-only"> indikator ·</span>
                            <span class="sm:hidden">{{ $number($row['total']) }} observasi</span>
                        </p>

                        <p class="hidden text-right text-sm font-semibold tabular-nums text-navy-900 dark:text-slate-100 sm:block" aria-hidden="true">{{ $number($row['total']) }}</p>

                        <div class="col-start-2 row-span-2 row-start-1 sm:col-start-4 sm:row-span-1">
                            @if ($row['reported'])
                                <x-badge color="green" dot>Sudah melapor</x-badge>
                            @elseif ($row['indicators'] > 0)
                                <x-badge color="amber" dot>Belum melapor</x-badge>
                            @else
                                <x-badge color="gray" dot>Belum ada indikator</x-badge>
                            @endif
                        </div>
                    </li>
                @empty
                    <li>
                        <x-empty-state icon="building" title="Belum ada unit yang dapat ditampilkan." />
                    </li>
                @endforelse
            </ul>
        </x-card>
    </div>
</x-app-layout>

<x-app-layout title="Laporan Observasi Mutu">
    <x-slot name="actions">
        @can('create', App\Models\Observation::class)
            <x-button :href="route('observations.create')" icon="plus">Tambah Observasi</x-button>
        @endcan
    </x-slot>

    @php
        $user = Auth::user();
        // Switching category keeps the period and unit; the indicator is chosen again.
        $keep = array_filter(['start_date' => $report->startDate, 'end_date' => $report->endDate, 'unit_id' => $report->unit?->id]);
        $unitOptions = [['value' => \App\Support\ReportFilters::ALL_UNITS, 'label' => 'Semua Unit'],
            ...$units->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->all()];
    @endphp

    <div class="space-y-6">
        <x-flash />

        @if ($user->isPicUnit() && ! $user->unit_id)
            <x-alert type="warning">
                Akun Anda belum terhubung dengan unit, sehingga belum ada data yang dapat ditampilkan. Hubungi administrator.
            </x-alert>
        @endif

        {{-- 1. Category, 2. one indicator, 3. period, 4. unit --}}
        <section class="card p-4 sm:p-5 space-y-5" aria-label="Filter laporan">
            <nav aria-label="Kategori indikator" class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <ul class="inline-flex min-w-max gap-1 rounded-xl bg-gray-100 p-1 dark:bg-slate-800/70">
                    @foreach ($categories as $slug => $tab)
                        @php
                            $active = $slug === $report->category;
                        @endphp
                        <li>
                            <a href="{{ route('observations.index', ['kategori' => $slug, ...$keep]) }}" data-report-category="{{ $slug }}"
                                @if ($active) aria-current="page" @endif
                                @class([
                                    'flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium transition',
                                    'bg-white text-navy-900 shadow-sm dark:bg-slate-900 dark:text-slate-100' => $active,
                                    'text-gray-600 hover:text-navy-900 dark:text-slate-400 dark:hover:text-slate-200' => ! $active,
                                ])>
                                {{ $tab['label'] }}
                                <span @class([
                                    'rounded-full px-1.5 text-xs tabular-nums',
                                    'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' => $active,
                                    'bg-gray-200/70 text-gray-500 dark:bg-slate-700 dark:text-slate-400' => ! $active,
                                ])>{{ $tab['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <form method="GET" action="{{ route('observations.index') }}"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_10rem_10rem_minmax(0,1.3fr)_auto] lg:items-start">
                <input type="hidden" name="kategori" value="{{ $report->category }}" />

                <x-form.field label="Indikator" for="indicator_id" required class="sm:col-span-2 lg:col-span-1">
                    @if ($indicators->isEmpty())
                        <p class="flex h-11 items-center rounded-lg border border-dashed border-gray-300 px-3.5 text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">
                            Belum ada indikator {{ $report->categoryLabel() }} yang dapat dilaporkan.
                        </p>
                    @else
                        <x-form.combobox name="indicator_id" required
                            :options="$indicators->map(fn ($i) => ['value' => (string) $i->id, 'label' => $i->name.($i->is_active ? '' : ' (nonaktif)')])->all()"
                            :selected="$report->indicator ? ['value' => (string) $report->indicator->id, 'label' => $report->indicator->name] : null"
                            placeholder="Pilih satu indikator {{ $report->categoryLabel() }}…" search-placeholder="Cari nama indikator…"
                            empty-text="Tidak ada indikator." />
                    @endif
                </x-form.field>

                <x-form.field label="Tanggal Mulai" for="start_date" required>
                    <x-form.input type="date" name="start_date" :value="$report->startDate" required />
                </x-form.field>

                <x-form.field label="Tanggal Akhir" for="end_date" required>
                    <x-form.input type="date" name="end_date" :value="$report->endDate" required />
                </x-form.field>

                <x-form.field label="Unit" for="unit_id">
                    @if ($user->canViewAllUnits())
                        <x-form.combobox name="unit_id" :options="$unitOptions"
                            :selected="$report->unit ? ['value' => (string) $report->unit->id, 'label' => $report->unit->name] : $unitOptions[0]"
                            search-placeholder="Cari unit…" empty-text="Tidak ada unit." />
                    @else
                        {{-- PIC Unit: always their own unit. --}}
                        <p class="flex h-11 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3.5 text-sm text-gray-700 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300">
                            <x-icon name="building" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                            <span class="truncate">{{ $user->unit?->name ?? '-' }}</span>
                        </p>
                    @endif
                </x-form.field>

                <div class="flex gap-2 lg:pt-[1.625rem]">
                    <x-button variant="navy" icon="filter" loading-text="Memuat…" class="flex-1 lg:flex-none">Tampilkan</x-button>
                </div>
            </form>
        </section>

        @if (! $result)
            <x-card>
                <x-empty-state icon="chart" title="Pilih satu indikator untuk menampilkan laporan." />
            </x-card>
        @else
            @php
                $layout = $result['layout'];
                $observations = $result['observations'];
            @endphp

            {{-- Report header: the indicator, the scope and the counts. --}}
            <section class="card p-4 sm:p-5" aria-labelledby="report-title">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">
                            {{ \App\Support\ObservationWorkbook::categoryLabel($report->indicator) }}
                        </p>
                        <h2 id="report-title" class="mt-1 text-lg font-bold leading-snug text-navy-900 dark:text-slate-100 sm:text-xl">{{ $report->indicator->name }}</h2>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-slate-400">
                            <span class="inline-flex items-center gap-1.5"><x-icon name="calendar" class="h-4 w-4" />{{ \App\Support\ObservationWorkbook::periodLabel($report->scope()) }}</span>
                            <span class="inline-flex items-center gap-1.5"><x-icon name="building" class="h-4 w-4" />{{ $report->unit?->name ?? 'Semua unit' }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <dl class="flex gap-3">
                            <div class="rounded-xl bg-gray-50 px-4 py-2 dark:bg-slate-800/60">
                                <dt class="text-xs text-gray-500 dark:text-slate-400">Jumlah Observasi</dt>
                                <dd class="text-lg font-bold tabular-nums text-navy-900 dark:text-slate-100" data-report-total>{{ $result['total'] }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-2 dark:bg-slate-800/60">
                                <dt class="text-xs text-gray-500 dark:text-slate-400">Unit Melapor</dt>
                                <dd class="text-lg font-bold tabular-nums text-navy-900 dark:text-slate-100" data-report-units>{{ $result['units'] }}</dd>
                            </div>
                        </dl>

                        @can('export', App\Models\Observation::class)
                            <div class="flex gap-2">
                                <x-button variant="secondary" size="sm" :href="route('observations.export', ['format' => 'excel', ...$report->query()])" icon="download" data-no-progress>
                                    Excel
                                </x-button>
                                <x-button variant="secondary" size="sm" :href="route('observations.export', ['format' => 'pdf', ...$report->query()])" icon="document" data-no-progress>
                                    PDF
                                </x-button>
                            </div>
                        @endcan
                    </div>
                </div>
            </section>

            {{-- One observation per row, one column per form answer; wide forms scroll sideways. --}}
            <x-card :padded="false">
                <div class="relative overflow-x-auto">
                    @php
                        // One width rule per column, shared by heading and cells, so every row lines up the same way.
                        $columnClass = fn (int $index) => match (true) {
                            $index === 0 => 'w-14 whitespace-nowrap tabular-nums',                 // No
                            $index === 1 => 'min-w-[8.5rem] whitespace-nowrap tabular-nums',       // Tanggal
                            $index === 2, $index === 3 => 'min-w-[12rem] max-w-[16rem]',           // Unit, Diinput oleh
                            default => 'min-w-[10rem] max-w-[18rem]',                              // form answers
                        };
                    @endphp
                    <table class="table-base table-report text-sm" data-report-table>
                        <thead>
                            <tr>
                                @foreach ($layout->headings() as $heading)
                                    <th class="{{ $columnClass($loop->index) }}">{{ $heading }}</th>
                                @endforeach
                                <th class="w-28">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($observations as $observation)
                                <tr>
                                    @foreach ($layout->row($observation, $observations->firstItem() + $loop->index) as $cell)
                                        <td @class([
                                            $columnClass($loop->index),
                                            'text-gray-500 dark:text-slate-400' => $loop->index === 0,
                                            'font-medium text-navy-900 dark:text-slate-100' => $loop->index === 1,
                                        ])>
                                            @if ($loop->index >= 4 && ($cell === 'Ya' || $cell === 'Tidak'))
                                                <x-badge :color="$cell === 'Ya' ? 'green' : 'red'">{{ $cell }}</x-badge>
                                            @else
                                                <span class="cell-text">{{ $cell }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="w-28">
                                        <div class="flex items-center justify-center gap-1">
                                            <a href="{{ route('observations.show', $observation) }}" class="btn-icon" title="Detail" aria-label="Detail">
                                                <x-icon name="eye" class="h-[18px] w-[18px]" />
                                            </a>
                                            @can('update', $observation)
                                                <a href="{{ route('observations.edit', $observation) }}" class="btn-icon" title="Edit" aria-label="Edit">
                                                    <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                                </a>
                                            @endcan
                                            @can('delete', $observation)
                                                <x-delete-form :action="route('observations.destroy', $observation)"
                                                    confirm="Yakin ingin menghapus observasi ini? Tindakan ini tidak dapat dibatalkan." />
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($layout->headings()) + 1 }}">
                                        <x-empty-state icon="clipboard" title="Belum ada observasi untuk filter ini."
                                            description="Ubah periode atau unit untuk melihat data indikator ini." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($observations->hasPages())
                    <div class="border-t border-gray-100 px-5 py-4 dark:border-slate-800 sm:px-6">
                        {{ $observations->links() }}
                    </div>
                @endif
            </x-card>
        @endif
    </div>
</x-app-layout>

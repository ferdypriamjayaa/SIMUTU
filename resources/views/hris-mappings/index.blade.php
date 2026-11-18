@php
    // HRIS "divisi" holds the unit's name (e.g. Instalasi Gawat Darurat) and
    // "departemen" its type (e.g. Pelayanan Medik): the unit name is the title.
    $placementLabel = fn (?string $division, ?string $department) => $division ?? $department ?? '-';
    $placementType = fn (?string $division, ?string $department) => match (true) {
        $division !== null && $department !== null => $department,
        $division !== null => 'Tanpa departemen',
        default => 'Tanpa divisi',
    };
    $mappedCount = collect($placements)->filter(fn ($p) => $byPlacement->has($p['division']."\n".$p['department']))->count();
@endphp

<x-app-layout title="Mapping Unit HRIS">
    @if ($company && ! $error)
        <x-slot name="actions">
            <form method="POST" action="{{ route('hris-mappings.refresh') }}">
                @csrf
                <input type="hidden" name="company_code" value="{{ $company }}">
                <x-button variant="secondary" icon="reset" loading-text="Memuat…">Muat Ulang Data HRIS</x-button>
            </form>
        </x-slot>
    @endif

    <div class="space-y-6">
        <x-flash />

        @if (count($companies) > 1)
            <nav class="flex gap-1 overflow-x-auto rounded-xl bg-white p-1 shadow-card ring-1 ring-gray-200/80 dark:bg-slate-900 dark:ring-slate-800 sm:inline-flex" aria-label="Perusahaan HRIS">
                @foreach ($companies as $code => $label)
                    <a href="{{ route('hris-mappings.index', ['company' => $code]) }}" @if ($code === $company) aria-current="page" @endif
                        @class([
                            'whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition',
                            'bg-navy-800 text-white shadow-sm dark:bg-slate-700' => $code === $company,
                            'text-gray-600 hover:bg-gray-50 hover:text-navy-900 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-slate-100' => $code !== $company,
                        ])>{{ $label }} <span class="font-normal opacity-70">({{ $code }})</span></a>
                @endforeach
            </nav>
        @endif

        @if ($error)
            <x-alert type="error" title="Data HRIS tidak dapat dimuat">{{ $error }}</x-alert>
        @endif

        {{-- Kept in the DOM (hidden when empty) so a mapping made in place can update it. --}}
        <div data-hris-unmapped @if ($unmappedUnits->isEmpty()) hidden @endif>
            @include('hris-mappings.partials.unmapped-units')
        </div>

        @if ($placements)
            <x-card :title="'Departemen HRIS — '.($companies[$company] ?? $company)"
                :description="count($placements).' departemen/divisi · '.$mappedCount.' sudah dipetakan'" icon="building" :padded="false"
                data-hris-placements>
                <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                    @foreach ($placements as $placement)
                        @php($mapped = $byPlacement->get($placement['division']."\n".$placement['department'], collect()))
                        <li class="px-5 py-4 sm:px-6" data-hris-placement>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-bold leading-snug text-navy-900 dark:text-slate-100">{{ $placementLabel($placement['division'], $placement['department']) }}</p>
                                    <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{{ $placementType($placement['division'], $placement['department']) }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">{{ $placement['employees'] }} karyawan aktif</p>
                                </div>

                                @if ($placement['division'] === null && $placement['department'] === null)
                                    <x-badge color="gray">Belum ditempatkan di HRIS · tidak dapat dipetakan</x-badge>
                                @else
                                    {{-- Submitted in place by resources/js/hris-mapping.js; a plain POST without JavaScript. --}}
                                    <form method="POST" action="{{ route('hris-mappings.store') }}" class="flex w-full gap-2 sm:w-auto" data-hris-map>
                                        @csrf
                                        <input type="hidden" name="company_code" value="{{ $company }}">
                                        <input type="hidden" name="division" value="{{ $placement['division'] }}">
                                        <input type="hidden" name="department" value="{{ $placement['department'] }}">
                                        <label class="sr-only" for="unit-{{ $loop->index }}">Unit untuk {{ $placementLabel($placement['division'], $placement['department']) }}</label>
                                        <select name="unit_id" id="unit-{{ $loop->index }}" required class="form-control form-control-sm min-w-0 flex-1 sm:w-60">
                                            <option value="">Pilih unit…</option>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" @disabled($mapped->contains('unit_id', $unit->id))>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-button size="sm" variant="navy" icon="plus" loading-text="Menyimpan…">Petakan</x-button>
                                    </form>
                                @endif
                            </div>

                            <div data-hris-mapped>
                                @include('hris-mappings.partials.mapped-units', ['mapped' => $mapped])
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <x-card title="Daftar Dokter — API Dokter" icon="users" :padded="false">
            @if ($doctorApi['configured'])
                <x-slot name="actions">
                    <form method="POST" action="{{ route('hris-mappings.refresh-doctors') }}">
                        @csrf
                        <input type="hidden" name="company" value="{{ $company }}">
                        <x-button size="sm" variant="secondary" icon="reset" loading-text="Memuat…">Muat Ulang Data Dokter</x-button>
                    </form>
                </x-slot>
            @endif

            @if ($doctorApi['error'])
                <div class="p-5 sm:p-6">
                    <x-alert :type="$doctorApi['configured'] ? 'error' : 'info'" title="Data dokter tidak dapat dimuat">{{ $doctorApi['error'] }}</x-alert>
                </div>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                    @forelse ($doctorApi['hospitals'] as $hospital)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6">
                            <div class="min-w-0 text-sm">
                                <p class="font-medium text-navy-900 dark:text-slate-100">{{ $hospital['name'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400">
                                    {{ $hospital['company'] ? 'Perusahaan HRIS: '.($companies[$hospital['company']] ?? $hospital['company']).' ('.$hospital['company'].')' : 'Tidak terhubung ke perusahaan HRIS' }}
                                </p>
                            </div>
                            <x-badge color="gray">{{ $hospital['doctors'] }} dokter aktif</x-badge>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-gray-500 dark:text-slate-400 sm:px-6">API Dokter tidak mengirimkan dokter aktif.</li>
                    @endforelse
                </ul>
            @endif
        </x-card>

        @if ($orphans->isNotEmpty())
            <x-card title="Pemetaan yang tidak ditemukan di HRIS" description="Departemen ini tersimpan di SIMUTU tetapi tidak lagi dilaporkan HRIS (berganti nama atau tidak ada karyawan aktif)." icon="warning" :padded="false">
                <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                    @foreach ($orphans as $mapping)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6">
                            <div class="min-w-0 text-sm">
                                <p class="text-base font-bold leading-snug text-navy-900 dark:text-slate-100">{{ $placementLabel($mapping->division, $mapping->department) }}</p>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{{ $placementType($mapping->division, $mapping->department) }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Unit SIMUTU: {{ $mapping->unit?->name }}</p>
                            </div>
                            <x-delete-form :action="route('hris-mappings.destroy', $mapping)" style="button" label="Hapus"
                                confirm="Hapus pemetaan ini? Departemen tersebut tidak ditemukan di HRIS." />
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    </div>
</x-app-layout>

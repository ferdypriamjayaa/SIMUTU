{{-- Active SIMUTU units without any HRIS placement. $unmappedUnits: Collection<string> of unit names. --}}
@if ($unmappedUnits->isNotEmpty())
    <p class="text-sm text-gray-500 dark:text-slate-400">
        <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $unmappedUnits->count() }} unit aktif</span>
        belum memiliki departemen HRIS: {{ $unmappedUnits->implode(', ') }}.
    </p>
@endif

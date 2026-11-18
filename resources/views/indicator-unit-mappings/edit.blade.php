@php
    $isInm = $indicator->category?->code === 'INM';
    $selected = array_map('intval', old('unit_ids', $mappedIds->all()));
    $back = route('indicator-unit-mappings.index', request()->query());
@endphp

<x-app-layout title="Atur Unit Indikator" :subtitle="$indicator->name" :back="$back">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-flash />

        @if ($isInm)
            <x-alert type="warning" title="Indikator INM">Unit yang dicentang dapat menginput observasi indikator ini. Observasi yang sudah ada tidak terhapus.</x-alert>
        @endif

        <form method="POST" action="{{ route('indicator-unit-mappings.update', [$indicator, ...request()->query()]) }}" class="card card-body space-y-5"
            x-data="{ count: {{ count($selected) }} }" x-on:change="count = $el.querySelectorAll('input[name=\'unit_ids[]\']:checked').length">
            @csrf
            @method('PUT')

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-slate-800">
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge color="navy">{{ $indicator->category?->name ?? '-' }}</x-badge>
                    @if ($indicator->unit)
                        <x-badge color="gray">Penanggung jawab: {{ $indicator->unit->name }}</x-badge>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-slate-400"><span class="font-semibold text-navy-900 dark:text-slate-100" x-text="count">{{ count($selected) }}</span> unit dipilih</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @forelse ($units as $unit)
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:border-brand-300 has-[:checked]:border-brand-400 has-[:checked]:bg-brand-50/50 dark:border-slate-800 dark:hover:border-brand-500/50 dark:has-[:checked]:bg-brand-500/10">
                        <input type="checkbox" name="unit_ids[]" value="{{ $unit->id }}" @checked(in_array($unit->id, $selected, true)) class="form-checkbox-base mt-0.5">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-navy-900 dark:text-slate-100">{{ $unit->name }}</span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-slate-400">
                                <span class="font-mono">{{ $unit->code }}</span>
                                @unless ($unit->is_active)
                                    <x-badge color="amber">unit nonaktif</x-badge>
                                @endunless
                            </span>
                        </span>
                    </label>
                @empty
                    <div class="sm:col-span-2">
                        <x-empty-state icon="building" title="Belum ada unit aktif." description="Tambahkan unit pada menu Unit terlebih dahulu." />
                    </div>
                @endforelse
            </div>

            <x-input-error :messages="$errors->get('unit_ids') ?: \Illuminate\Support\Arr::flatten($errors->get('unit_ids.*'))" />

            <x-form.actions :cancel="$back" submit="Simpan Mapping" sticky />
        </form>
    </div>
</x-app-layout>

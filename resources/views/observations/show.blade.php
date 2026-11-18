@php
    $back = in_array(url()->previous(), [url()->current(), route('observations.edit', $observation)], true)
        ? route('observations.index')
        : url()->previous();
@endphp

<x-app-layout title="Detail Observasi" :subtitle="$observation->indicator?->name ?? '-'" :back="$back">
    <x-slot name="actions">
        @can('delete', $observation)
            <x-delete-form :action="route('observations.destroy', $observation)" style="button"
                confirm="Yakin ingin menghapus observasi ini? Tindakan ini tidak dapat dibatalkan." />
        @endcan
        @can('update', $observation)
            <x-button :href="route('observations.edit', $observation)" icon="pencil">Edit</x-button>
        @endcan
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6">
        <x-flash />

        {{-- Record information --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['calendar', 'Tanggal Observasi', $observation->observation_date?->format('d-m-Y') ?? '-'],
                ['building', 'Unit', $observation->unit?->name ?? '-'],
                ['user', 'Diinput Oleh', $observation->submitterName()],
                ['clipboard', 'Waktu Input', $observation->created_at?->format('d-m-Y H:i') ?? '-'],
            ] as [$icon, $label, $value])
                <div class="card p-4">
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-slate-400">
                        <x-icon :name="$icon" class="h-4 w-4 text-brand-500 dark:text-brand-400" /> {{ $label }}
                    </div>
                    <p class="mt-1.5 truncate text-sm font-semibold text-navy-900 dark:text-slate-100" title="{{ $value }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        {{-- Recorded values --}}
        <x-card title="Hasil Observasi" :description="$observation->indicator?->category?->name" icon="list" :padded="false">
            <dl class="divide-y divide-gray-100 dark:divide-slate-800">
                @forelse ($values as ['label' => $label, 'value' => $value, 'type' => $type])
                    <div class="grid grid-cols-1 gap-1 px-5 py-3.5 sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm text-gray-500 dark:text-slate-400">{{ $label }}</dt>
                        <dd class="whitespace-pre-line text-sm font-medium text-navy-900 dark:text-slate-100 sm:col-span-2">
                            @if ($type === 'yes_no' && $value === 'Ya')
                                <x-badge color="green" dot>Ya</x-badge>
                            @elseif ($type === 'yes_no' && $value === 'Tidak')
                                <x-badge color="red" dot>Tidak</x-badge>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @empty
                    <div class="px-6 py-4">
                        <x-empty-state icon="document" title="Tidak ada isian formulir untuk observasi ini." />
                    </div>
                @endforelse
            </dl>

            @if ($version)
                <x-slot name="footer">Formulir versi {{ $version->version }}</x-slot>
            @endif
        </x-card>
    </div>
</x-app-layout>

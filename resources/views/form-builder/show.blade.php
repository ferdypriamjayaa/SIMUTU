@php
    use App\Models\IndicatorFormVersion;

    $statusColors = [
        IndicatorFormVersion::STATUS_DRAFT => 'amber',
        IndicatorFormVersion::STATUS_PUBLISHED => 'green',
        IndicatorFormVersion::STATUS_ARCHIVED => 'gray',
    ];
@endphp

<x-app-layout title="Formulir Indikator" :subtitle="$indicator->name"
    :back="route('indicators.show', ['category' => $indicator->category->slug])">
    <x-slot name="actions">
        @if ($draft)
            <x-button :href="route('form-builder.edit', [$indicator, $draft])" icon="pencil">Lanjutkan Draf v{{ $draft->version }}</x-button>
        @else
            <form method="POST" action="{{ route('form-builder.draft.store', $indicator) }}">
                @csrf
                <x-button icon="{{ $published ? 'pencil' : 'plus' }}" loading-text="Menyiapkan…">
                    {{ $published ? 'Edit Formulir' : 'Buat Formulir' }}
                </x-button>
            </form>
        @endif
    </x-slot>

    <div class="space-y-6">
        <x-flash />

        {{-- Current state --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="text-xs font-medium text-gray-500 dark:text-slate-400">Formulir yang digunakan</p>
                <p class="mt-1.5 text-lg font-bold text-navy-900 dark:text-slate-100">
                    {{ $published ? 'Versi '.$published->version : 'Belum ada' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    {{ $published?->published_at ? 'Terbit '.$published->published_at->format('d-m-Y H:i') : 'Publikasikan draf agar formulir dapat diisi.' }}
                </p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium text-gray-500 dark:text-slate-400">Draf</p>
                <p class="mt-1.5 text-lg font-bold text-navy-900 dark:text-slate-100">{{ $draft ? 'Versi '.$draft->version : 'Tidak ada' }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    {{ $draft ? 'Terakhir diubah '.$draft->updated_at->format('d-m-Y H:i') : 'Perubahan formulir selalu dibuat sebagai versi baru.' }}
                </p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-medium text-gray-500 dark:text-slate-400">Jumlah versi</p>
                <p class="mt-1.5 text-lg font-bold text-navy-900 dark:text-slate-100">{{ $versions->count() }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Versi lama tetap tersimpan untuk data observasi sebelumnya.</p>
            </div>
        </div>

        <x-card title="Riwayat Versi" icon="list" :padded="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Versi</th>
                            <th>Status</th>
                            <th class="text-right">Field</th>
                            <th class="text-right">Observasi</th>
                            <th>Diterbitkan</th>
                            <th class="text-right"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($versions as $version)
                            @php($observationCount = $version->observations_count + ($version->id === $baselineId ? $legacyCount : 0))
                            <tr>
                                <td class="whitespace-nowrap font-semibold text-navy-900 dark:text-slate-100">Versi {{ $version->version }}</td>
                                <td><x-badge :color="$statusColors[$version->status] ?? 'gray'" dot>{{ $version->statusLabel() }}</x-badge></td>
                                <td class="text-right tabular-nums">{{ $version->fields_count }}</td>
                                <td class="text-right tabular-nums">{{ $observationCount }}</td>
                                <td class="whitespace-nowrap text-gray-500 dark:text-slate-400">
                                    @if ($version->published_at)
                                        {{ $version->published_at->format('d-m-Y H:i') }}
                                        @if ($version->publisher)
                                            <span class="block text-xs">oleh {{ $version->publisher->name }}</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('form-builder.preview', [$indicator, $version]) }}" class="btn-icon" title="Pratinjau" aria-label="Pratinjau versi {{ $version->version }}">
                                            <x-icon name="eye" class="h-[18px] w-[18px]" />
                                        </a>

                                        @if ($version->isDraft())
                                            <a href="{{ route('form-builder.edit', [$indicator, $version]) }}" class="btn-icon" title="Edit draf" aria-label="Edit draf">
                                                <x-icon name="pencil" class="h-[18px] w-[18px]" />
                                            </a>

                                            <form method="POST" action="{{ route('form-builder.publish', [$indicator, $version]) }}" class="inline"
                                                onsubmit="return confirm('Publikasikan versi {{ $version->version }}? Observasi baru akan langsung memakai versi ini, dan versi yang terbit tidak dapat diubah lagi.')">
                                                @csrf
                                                <button type="submit" class="btn-icon" title="Publikasikan" aria-label="Publikasikan">
                                                    <x-icon name="check-circle" class="h-[18px] w-[18px] text-accent-600 dark:text-accent-300" />
                                                </button>
                                            </form>

                                            <x-delete-form :action="route('form-builder.destroy', [$indicator, $version])" label="Hapus draf"
                                                confirm="Hapus draf versi {{ $version->version }}? Semua perubahan pada draf ini akan hilang. Formulir yang sudah terbit tidak terpengaruh." />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state icon="clipboard" title="Indikator ini belum memiliki formulir."
                                        description="Buat formulir untuk menentukan isian observasi indikator ini." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>

<x-app-layout title="Pratinjau Formulir" :subtitle="$indicator->name.' · Versi '.$version->version.' ('.$version->statusLabel().')'"
    :back="$version->isDraft() ? route('form-builder.edit', [$indicator, $version]) : route('form-builder.show', $indicator)">
    @if ($version->isDraft())
        <x-slot name="actions">
            <x-button variant="secondary" :href="route('form-builder.edit', [$indicator, $version])" icon="pencil">Kembali Mengedit</x-button>

            <form method="POST" action="{{ route('form-builder.publish', [$indicator, $version]) }}"
                onsubmit="return confirm('Publikasikan versi {{ $version->version }}? Observasi baru akan langsung memakai versi ini, dan versi yang terbit tidak dapat diubah lagi.')">
                @csrf
                <x-button icon="check-circle" loading-text="Mempublikasikan…">Publikasikan</x-button>
            </form>
        </x-slot>
    @endif

    <div class="mx-auto max-w-4xl space-y-6">
        <x-flash />

        {{-- Not a real form: there is nothing to submit. --}}
        <div class="card card-body space-y-6">
            <div class="border-b border-gray-100 pb-5 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">Formulir Observasi</p>
                <h2 class="mt-1 text-lg font-bold text-navy-900 dark:text-slate-100">{{ $indicator->name }}</h2>
            </div>

            @if ($version->activeFields()->isEmpty())
                <x-empty-state icon="clipboard" title="Formulir ini belum memiliki field aktif." />
            @else
                @include('observations.partials.fields', ['version' => $version, 'values' => $values])
            @endif
        </div>
    </div>
</x-app-layout>

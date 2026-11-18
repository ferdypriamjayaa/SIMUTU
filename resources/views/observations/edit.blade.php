<x-app-layout title="Edit Observasi Mutu" :subtitle="$observation->indicator->name" :back="route('observations.show', $observation)">
    <div class="mx-auto max-w-4xl space-y-6">
        <x-flash />

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600 dark:text-slate-300">
            <span class="inline-flex items-center gap-2">
                <x-icon name="building" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                Unit: <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $observation->unit?->name ?? '-' }}</span>
            </span>
            <span class="inline-flex items-center gap-2">
                <x-icon name="user" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                Diinput oleh: <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $observation->submitterName() }}</span>
            </span>
        </div>

        <form action="{{ route('observations.update', $observation) }}" method="POST" class="card card-body space-y-6">
            @csrf
            @method('PUT')

            <div class="border-b border-gray-100 dark:border-slate-800 pb-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">Formulir Observasi</p>
                <h2 class="mt-1 text-lg font-bold text-navy-900 dark:text-slate-100">{{ $observation->indicator->name }}</h2>
            </div>

            @include('observations.partials.fields', [
                'version' => $version,
                'values' => $values,
                'employees' => $employees,
                'doctors' => $doctors,
                'date' => $observation->observation_date?->format('Y-m-d'),
                // Dropdown searches run for the observation's unit, not the editor's.
                'lookup' => ['observation' => $observation->id],
            ])

            <x-form.actions :cancel="route('observations.show', $observation)" submit="Simpan Perubahan" sticky />
        </form>
    </div>
</x-app-layout>

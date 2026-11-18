<x-app-layout title="Edit Indikator" :subtitle="$category->name" :back="route('indicators.show', ['category' => $category->slug])">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-flash />

        <form action="{{ route('indicators.update', ['category' => $category->slug, 'indicator' => $indicator->id]) }}"
            method="POST" class="card card-body space-y-5">
            @csrf
            @method('PUT')

            <x-form.field label="Nama indikator" for="name" required>
                <x-form.input name="name" :value="$indicator->name" maxlength="255" required autofocus />
            </x-form.field>

            <x-form.actions :cancel="route('indicators.show', ['category' => $category->slug])" submit="Simpan Perubahan" />
        </form>
    </div>
</x-app-layout>

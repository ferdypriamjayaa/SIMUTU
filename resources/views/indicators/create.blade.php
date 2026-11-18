<x-app-layout title="Tambah Indikator" :subtitle="$category->name" :back="route('indicators.show', ['category' => $category->slug])">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-flash />

        <form action="{{ route('indicators.store', ['category' => $category->slug]) }}" method="POST" class="card card-body space-y-5">
            @csrf

            <x-form.field label="Nama indikator" for="name" required>
                <x-form.input name="name" maxlength="255" required autofocus />
            </x-form.field>

            @if ($category->code === 'IMP_UNIT')
                <x-form.field label="Unit Penanggung Jawab" for="unit_id" required>
                    <x-form.select name="unit_id" required>
                        <option value="">— Pilih Unit —</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected(old('unit_id') == $unit->id)>{{ $unit->name }}</option>
                        @endforeach
                    </x-form.select>
                </x-form.field>
            @endif

            <x-form.actions :cancel="route('indicators.show', ['category' => $category->slug])" />
        </form>
    </div>
</x-app-layout>

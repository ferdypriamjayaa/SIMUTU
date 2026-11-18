{{-- Validation summary. Success/error session messages are shown as toasts by the layout. --}}
@if ($errors->any())
    <x-alert type="error" title="Periksa kembali isian yang ditandai." class="mb-6">
        <ul class="list-inside list-disc space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

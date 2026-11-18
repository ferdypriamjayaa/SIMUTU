@props(['cancel' => null, 'cancelLabel' => 'Batal', 'submit' => 'Simpan', 'loadingText' => 'Menyimpan…', 'sticky' => false])

{{-- Form footer: Batal on the left of Simpan, both right-aligned, on every form. --}}
<div {{ $attributes->class([
    'flex flex-col-reverse gap-3 border-t border-gray-100 dark:border-slate-800 pt-5 sm:flex-row sm:items-center sm:justify-end',
    'sticky bottom-0 z-10 -mx-5 -mb-5 rounded-b-2xl bg-white/95 dark:bg-slate-900/95 px-5 pb-5 backdrop-blur sm:-mx-6 sm:-mb-6 sm:px-6 sm:pb-6' => $sticky,
]) }}>
    {{ $slot }}
    @if ($cancel)
        <x-button variant="secondary" :href="$cancel">{{ $cancelLabel }}</x-button>
    @endif
    <x-button icon="check-circle" :loading-text="$loadingText">{{ $submit }}</x-button>
</div>

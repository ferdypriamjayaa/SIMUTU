{{-- Light/dark switch: moon while light (go dark), sun while dark (go light). Wired up in resources/js/app.js. --}}
<button type="button" data-theme-toggle {{ $attributes->merge(['class' => 'btn-icon']) }}
    title="Ganti tema" aria-label="Ganti tema terang/gelap">
    <x-icon name="moon" class="h-5 w-5 dark:hidden" />
    <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
</button>

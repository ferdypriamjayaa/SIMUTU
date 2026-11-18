@props(['action', 'confirm', 'label' => 'Hapus', 'style' => 'icon'])

{{-- Delete with a confirmation prompt: style icon (table rows) | button. --}}
<form action="{{ $action }}" method="POST" {{ $attributes->merge(['class' => 'inline']) }}
    onsubmit="return confirm(@js($confirm))">
    @csrf
    @method('DELETE')

    @if ($style === 'button')
        <x-button variant="secondary" icon="trash" loading-text="Menghapus…"
            class="text-red-600 dark:text-red-400 hover:border-red-300 dark:hover:border-red-500/50 hover:bg-red-50 dark:hover:bg-red-500/15 hover:text-red-700 dark:hover:text-red-300">{{ $label }}</x-button>
    @else
        <button type="submit" class="btn-icon btn-icon-danger" title="{{ $label }}" aria-label="{{ $label }}">
            <x-icon name="trash" class="h-[18px] w-[18px]" />
        </button>
    @endif
</form>

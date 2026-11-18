@props(['messages'])

@if ($messages)
    <div {{ $attributes->merge(['class' => 'form-error']) }}>
        <x-icon name="warning" class="mt-px h-3.5 w-3.5" />
        <span>{{ implode(' ', (array) $messages) }}</span>
    </div>
@endif

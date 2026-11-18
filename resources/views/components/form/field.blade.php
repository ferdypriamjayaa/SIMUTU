@props(['label' => null, 'for' => null, 'error' => null, 'bag' => 'default', 'hint' => null, 'required' => false, 'optional' => false])

{{-- Label, control, hint and the field's own validation message, always in the same order. --}}
@php($errorKey = $error ?? $for)

<div {{ $attributes }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif @class(['form-label', 'form-label-required' => $required])>
            {{ $label }}
            @if ($optional)
                <span class="font-normal text-gray-400 dark:text-slate-500">(opsional)</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="form-hint">{{ $hint }}</p>
    @endif

    @if ($errorKey)
        <x-input-error :messages="$errors->getBag($bag)->get($errorKey)" />
    @endif
</div>

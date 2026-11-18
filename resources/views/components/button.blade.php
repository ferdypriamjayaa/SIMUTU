@props([
    'variant' => 'primary',
    'size' => null,
    'href' => null,
    'icon' => null,
    'type' => 'submit',
    'loadingText' => null,
])

{{-- One button for links and form submits: primary | navy | secondary | danger | ghost. --}}
@php
    $classes = trim('btn btn-'.$variant.($size === 'sm' ? ' btn-sm' : ''));
    $iconClass = $size === 'sm' ? 'h-4 w-4' : 'h-[18px] w-[18px]';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :class="$iconClass" />@endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" @if ($loadingText) data-loading-text="{{ $loadingText }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :class="$iconClass" />@endif
        <span>{{ $slot }}</span>
    </button>
@endif

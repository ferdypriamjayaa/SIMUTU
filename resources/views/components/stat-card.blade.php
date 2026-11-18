@props(['label', 'value', 'hint' => null, 'icon' => 'chart', 'tone' => 'brand'])

@php
    $tones = [
        'brand' => 'bg-brand-50 dark:bg-brand-500/15 text-brand-600 dark:text-brand-300',
        'navy' => 'bg-navy-50 dark:bg-navy-400/15 text-navy-600 dark:text-navy-300',
        'green' => 'bg-accent-50 dark:bg-accent-500/15 text-accent-600 dark:text-accent-300',
        'amber' => 'bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'card relative overflow-hidden p-4 sm:p-5']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-medium text-gray-500 dark:text-slate-400">{{ $label }}</p>
            <p class="mt-2 text-2xl font-bold tracking-tight text-navy-900 dark:text-slate-100 sm:text-3xl">{{ $value }}</p>
        </div>
        <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:flex {{ $tones[$tone] ?? $tones['brand'] }}">
            <x-icon :name="$icon" class="h-6 w-6" />
        </span>
    </div>
    @if ($hint)
        <p class="mt-3 text-xs text-gray-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>

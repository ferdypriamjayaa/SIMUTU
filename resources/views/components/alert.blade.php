@props(['type' => 'info', 'title' => null])

{{-- Inline message: info | success | warning | error. --}}
@php
    $styles = [
        'info' => ['bg-navy-50 dark:bg-navy-400/15 border-navy-200 dark:border-navy-400/30 text-navy-800 dark:text-slate-200', 'info', 'text-navy-500 dark:text-navy-300'],
        'success' => ['bg-accent-50 dark:bg-accent-500/15 border-accent-200 dark:border-accent-500/30 text-accent-800 dark:text-accent-200', 'check-circle', 'text-accent-600 dark:text-accent-300'],
        'warning' => ['bg-amber-50 dark:bg-amber-500/15 border-amber-200 dark:border-amber-500/30 text-amber-900 dark:text-amber-200', 'warning', 'text-amber-500 dark:text-amber-400'],
        'error' => ['bg-red-50 dark:bg-red-500/15 border-red-200 dark:border-red-500/30 text-red-800 dark:text-red-200', 'x-circle', 'text-red-500 dark:text-red-400'],
    ][$type] ?? ['bg-navy-50 dark:bg-navy-400/15 border-navy-200 dark:border-navy-400/30 text-navy-800 dark:text-slate-200', 'info', 'text-navy-500 dark:text-navy-300'];
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'flex gap-3 rounded-xl border p-4 text-sm '.$styles[0]]) }}>
    <x-icon :name="$styles[1]" class="mt-0.5 h-5 w-5 {{ $styles[2] }}" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>

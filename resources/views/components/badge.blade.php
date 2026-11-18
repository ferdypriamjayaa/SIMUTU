@props(['color' => 'gray', 'dot' => false])

{{-- Status colours: green (aktif/ya), red (tidak), amber (perhatian), brand, navy, gray. --}}
@php
    $tones = [
        'green' => 'bg-accent-50 dark:bg-accent-500/15 text-accent-700 dark:text-accent-300 ring-accent-600/20 dark:ring-accent-400/30',
        'red' => 'bg-red-50 dark:bg-red-500/15 text-red-700 dark:text-red-300 ring-red-600/15 dark:ring-red-400/30',
        'amber' => 'bg-amber-50 dark:bg-amber-500/15 text-amber-800 dark:text-amber-200 ring-amber-600/20 dark:ring-amber-400/30',
        'brand' => 'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 ring-brand-600/20 dark:ring-brand-400/30',
        'navy' => 'bg-navy-50 dark:bg-navy-400/15 text-navy-700 dark:text-navy-200 ring-navy-600/20 dark:ring-navy-300/30',
        'gray' => 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-300 ring-gray-500/15 dark:ring-slate-400/20',
    ];
    $dots = ['green' => 'bg-accent-500', 'red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'brand' => 'bg-brand-500', 'navy' => 'bg-navy-500', 'gray' => 'bg-gray-400'];
@endphp

<span {{ $attributes->merge(['class' => 'badge '.($tones[$color] ?? $tones['gray'])]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dots[$color] ?? $dots['gray'] }}"></span>
    @endif
    {{ $slot }}
</span>

@props(['title' => null, 'description' => null, 'icon' => null, 'padded' => true])

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="card-header">
            <div class="flex min-w-0 items-center gap-3">
                @if ($icon)
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-500/15 text-brand-600 dark:text-brand-300">
                        <x-icon :name="$icon" class="h-5 w-5" />
                    </span>
                @endif
                <div class="min-w-0">
                    @if ($title)
                        <h3 class="card-title">{{ $title }}</h3>
                    @endif
                    @if ($description)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['card-body' => $padded])>
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="card-footer">{{ $footer }}</footer>
    @endisset
</section>

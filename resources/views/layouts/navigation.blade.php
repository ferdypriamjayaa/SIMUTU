@php
    $user = Auth::user();
    $groups = [
        'Menu Utama' => [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'active' => 'dashboard', 'show' => true],
            ['route' => 'observations.index', 'label' => 'Laporan Observasi', 'icon' => 'clipboard', 'active' => ['observations.index', 'observations.show', 'observations.edit'], 'show' => $user->can('viewAny', App\Models\Observation::class)],
            ['route' => 'observations.create', 'label' => 'Input Observasi', 'icon' => 'plus-circle', 'active' => 'observations.create*', 'show' => $user->can('create', App\Models\Observation::class)],
        ],
        'Master Data' => [
            ['route' => ['indicators.show', ['category' => 'inm']], 'label' => 'Indikator', 'icon' => 'chart', 'active' => 'indicators.*', 'show' => $user->canViewAllUnits()],
            ['route' => 'units.index', 'label' => 'Unit', 'icon' => 'building', 'active' => 'units.*', 'show' => $user->isAdmin()],
            ['route' => 'indicator-unit-mappings.index', 'label' => 'Mapping Indikator & Unit', 'icon' => 'list', 'active' => 'indicator-unit-mappings.*', 'show' => $user->isAdmin()],
            ['route' => 'hris-mappings.index', 'label' => 'Mapping Unit HRIS', 'icon' => 'users', 'active' => 'hris-mappings.*', 'show' => $user->isAdmin()],
            ['route' => 'users.index', 'label' => 'Pengguna', 'icon' => 'users', 'active' => 'users.*', 'show' => $user->isAdmin()],
            ['route' => 'jotform-import.index', 'label' => 'Impor Jotform', 'icon' => 'download', 'active' => 'jotform-import.*', 'show' => $user->isAdmin()],
        ],
    ];
    $groups = array_filter(array_map(fn ($links) => array_filter($links, fn ($link) => $link['show']), $groups));
    $url = fn ($link) => is_array($link['route']) ? route(...$link['route']) : route($link['route']);
@endphp

{{-- Mobile/tablet backdrop --}}
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
    x-transition.opacity.duration.200ms
    class="fixed inset-0 z-40 bg-navy-950/50 backdrop-blur-sm lg:hidden"></div>

<aside
    class="vt-nav fixed inset-y-0 start-0 z-50 flex w-72 -translate-x-full flex-col bg-navy-900 text-navy-100 transition-transform dark:border-e dark:border-slate-800 dark:bg-slate-900 duration-300 ease-out lg:translate-x-0"
    :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': ! sidebarOpen }"
    aria-label="Navigasi utama">

    {{-- Brand --}}
    <div class="flex h-20 shrink-0 items-center gap-3 border-b border-white/10 px-5">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-1.5 shadow-sm">
                <x-application-logo class="h-full w-full" />
            </span>
            <span class="min-w-0 leading-tight">
                <span class="block text-lg font-bold tracking-tight text-white">SIMUTU</span>
                <span class="block truncate text-xs text-navy-200">RSU Syifa Medika</span>
            </span>
        </a>
        <button type="button" @click="sidebarOpen = false" class="ms-auto rounded-lg p-1.5 text-navy-200 hover:bg-white/10 hover:text-white lg:hidden" aria-label="Tutup menu">
            <x-icon name="x" class="h-5 w-5" />
        </button>
    </div>

    {{-- Links --}}
    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-6">
        @foreach ($groups as $group => $links)
            <div>
                <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-navy-300/80">{{ $group }}</p>
                <ul class="space-y-1">
                    @foreach ($links as $link)
                        @php($active = request()->routeIs(...(array) $link['active']))
                        <li>
                            <a href="{{ $url($link) }}" @if ($active) aria-current="page" @endif
                                @class([
                                    'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                    'bg-white/10 text-white' => $active,
                                    'text-navy-200 hover:bg-white/5 hover:text-white' => ! $active,
                                ])>
                                @if ($active)
                                    <span class="absolute inset-y-2 -start-3 w-1 rounded-e-full bg-brand-500"></span>
                                @endif
                                <x-icon :name="$link['icon']" :class="$active ? 'h-5 w-5 text-brand-300' : 'h-5 w-5 text-navy-300 group-hover:text-white'" />
                                {{ $link['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- Account --}}
    <div class="shrink-0 border-t border-white/10 p-4">
        <div class="flex items-center gap-3 rounded-xl bg-white/5 p-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-brand-200">
                <x-icon name="shield" class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1 leading-tight">
                <p class="truncate text-sm font-semibold text-white">{{ $user->roleLabel() }}</p>
                <p class="truncate text-xs text-navy-200">{{ $user->unit?->name ?? 'Semua unit' }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg p-1.5 text-navy-200 transition hover:bg-white/10 hover:text-white" title="Keluar" aria-label="Keluar">
                    <x-icon name="logout" class="h-5 w-5" />
                </button>
            </form>
        </div>
        <div class="mt-3 flex h-1 overflow-hidden rounded-full">
            <span class="flex-1 bg-brand-500"></span>
            <span class="flex-1 bg-accent-500"></span>
        </div>
    </div>
</aside>

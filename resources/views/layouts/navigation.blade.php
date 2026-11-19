@php
    $user = Auth::user();
    $groups = [
        'menu-utama' => ['label' => 'Menu Utama', 'icon' => 'lucide-house', 'links' => [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'lucide-layout-dashboard', 'active' => 'dashboard', 'show' => true],
            ['route' => 'observations.create', 'label' => 'Input Observasi', 'icon' => 'lucide-clipboard-plus', 'active' => 'observations.create*', 'show' => $user->can('create', App\Models\Observation::class)],
            ['route' => 'observations.index', 'label' => 'Laporan Observasi', 'icon' => 'lucide-clipboard-list', 'active' => ['observations.index', 'observations.show', 'observations.edit'], 'show' => $user->can('viewAny', App\Models\Observation::class)],
        ]],
        'master-data' => ['label' => 'Master Data', 'icon' => 'lucide-database', 'links' => [
            ['route' => ['indicators.show', ['category' => 'inm']], 'label' => 'Indikator', 'icon' => 'lucide-chart-column', 'active' => 'indicators.*', 'show' => $user->canViewAllUnits()],
            ['route' => 'units.index', 'label' => 'Unit', 'icon' => 'lucide-building-2', 'active' => 'units.*', 'show' => $user->isAdmin()],
            ['route' => 'users.index', 'label' => 'Pengguna', 'icon' => 'lucide-users', 'active' => 'users.*', 'show' => $user->isAdmin()],
        ]],
        'integrasi-sistem' => ['label' => 'Integrasi Sistem', 'icon' => 'lucide-plug', 'links' => [
            ['route' => 'indicator-unit-mappings.index', 'label' => 'Mapping Indikator & Unit', 'icon' => 'lucide-arrow-left-right', 'active' => 'indicator-unit-mappings.*', 'show' => $user->isAdmin()],
            ['route' => 'hris-mappings.index', 'label' => 'Mapping Unit HRIS', 'icon' => 'lucide-network', 'active' => 'hris-mappings.*', 'show' => $user->isAdmin()],
            ['route' => 'jotform-import.index', 'label' => 'Impor Jotform', 'icon' => 'lucide-import', 'active' => 'jotform-import.*', 'show' => $user->isAdmin()],
        ]],
    ];

    // Only what this account may open; a group left without links disappears.
    foreach ($groups as $key => $group) {
        $groups[$key]['links'] = array_filter($group['links'], fn ($link) => $link['show']);
        $groups[$key]['active'] = collect($groups[$key]['links'])->contains(fn ($link) => request()->routeIs(...(array) $link['active']));
    }

    $groups = array_filter($groups, fn ($group) => $group['links'] !== []);
    $openGroup = array_key_first(array_filter($groups, fn ($group) => $group['active'])) ?? array_key_first($groups);
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
    <nav x-data="{ openGroup: @js($openGroup) }" class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        @foreach ($groups as $key => $group)
            @php
                $isOpen = $key === $openGroup;
            @endphp
            <div>
                <button type="button" id="nav-{{ $key }}" aria-controls="nav-{{ $key }}-links"
                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                    x-bind:aria-expanded="(openGroup === @js($key)).toString()"
                    x-on:click="openGroup = openGroup === @js($key) ? null : @js($key)"
                    @class([
                        'group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm font-medium transition hover:bg-white/5 hover:text-white',
                        'text-white' => $group['active'],
                        'text-navy-200' => ! $group['active'],
                    ])>
                    <x-icon :name="$group['icon']" :class="$group['active'] ? 'h-5 w-5 text-brand-300' : 'h-5 w-5 text-navy-300 group-hover:text-white'" />
                    <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                    <x-icon name="lucide-chevron-down" class="h-4 w-4 text-navy-300 transition-transform duration-200 group-hover:text-white group-aria-expanded:rotate-180 motion-reduce:transition-none" />
                </button>

                <div id="nav-{{ $key }}-links" role="region" aria-labelledby="nav-{{ $key }}"
                    data-open="{{ $isOpen ? 'true' : 'false' }}"
                    x-bind:data-open="(openGroup === @js($key)).toString()"
                    x-bind:inert="openGroup !== @js($key)"
                    @unless ($isOpen) inert @endunless
                    class="grid grid-rows-[0fr] transition-[grid-template-rows] duration-200 ease-out data-[open=true]:grid-rows-[1fr] motion-reduce:transition-none">
                    <div class="min-h-0 overflow-hidden">
                        <ul class="my-1 ms-[1.375rem] space-y-1 border-s border-white/10 ps-2.5">
                            @foreach ($group['links'] as $link)
                                @php
                                    $active = request()->routeIs(...(array) $link['active']);
                                @endphp
                                <li>
                                    <a href="{{ $url($link) }}" @if ($active) aria-current="page" @endif
                                        @class([
                                            'group relative flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition',
                                            'bg-white/10 text-white' => $active,
                                            'text-navy-200 hover:bg-white/5 hover:text-white' => ! $active,
                                        ])>
                                        @if ($active)
                                            <span class="absolute inset-y-2 -start-[11px] w-1 rounded-e-full bg-brand-500"></span>
                                        @endif
                                        <x-icon :name="$link['icon']" :class="$active ? 'h-[18px] w-[18px] text-brand-300' : 'h-[18px] w-[18px] text-navy-300 group-hover:text-white'" />
                                        <span class="min-w-0 truncate">{{ $link['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
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

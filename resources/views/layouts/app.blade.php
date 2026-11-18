<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#172a46">

        <title>{{ $title ? $title.' · ' : '' }}SIMUTU RSU Syifa Medika</title>
        <link rel="icon" type="image/png" href="{{ asset('images/favicon-64.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <x-theme-script />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div id="page-progress" class="page-progress"></div>
        <x-toasts />

        <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false" class="min-h-screen">
            @include('layouts.navigation')

            {{-- Column: top bar, heading, content (grows), footer at the bottom of short pages. --}}
            <div class="flex min-h-screen flex-col lg:ps-72">
                {{-- Top bar --}}
                <div class="vt-topbar sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-200/80 dark:border-slate-800 bg-white/90 dark:bg-slate-900/80 px-4 backdrop-blur sm:px-6 lg:px-8">
                    <button type="button" @click="sidebarOpen = true" class="btn-icon -ms-1 lg:hidden" aria-label="Buka menu">
                        <x-icon name="menu" class="h-6 w-6" />
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 lg:hidden">
                        <x-application-logo class="h-8 w-8" />
                        <span class="text-sm font-bold tracking-tight text-navy-900 dark:text-slate-100">SIMUTU</span>
                    </a>

                    <p class="hidden items-center gap-2 text-sm text-gray-500 dark:text-slate-400 lg:flex">
                        <x-icon name="calendar" class="h-4 w-4" />
                        {{ now()->locale('id')->translatedFormat('l, d F Y') }}
                    </p>

                    @php($user = Auth::user())
                    <div class="ms-auto flex items-center gap-1 sm:gap-2">
                        <x-theme-toggle />

                        <x-dropdown align="right" width="w-64">
                            <x-slot name="trigger">
                                <button type="button" class="flex items-center gap-3 rounded-xl px-2 py-1.5 text-left transition hover:bg-gray-50 dark:hover:bg-slate-800/60">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 dark:bg-brand-500/15 text-sm font-semibold text-brand-700 dark:text-brand-300 ring-2 ring-white dark:ring-slate-900">
                                        {{ \Illuminate\Support\Str::of($user->name)->explode(' ')->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}
                                    </span>
                                    <span class="hidden min-w-0 sm:block">
                                        <span class="block max-w-[12rem] truncate text-sm font-semibold text-navy-900 dark:text-slate-100">{{ $user->name }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-slate-400">{{ $user->roleLabel() }}</span>
                                    </span>
                                    <x-icon name="chevron-down" class="hidden h-4 w-4 text-gray-400 dark:text-slate-500 sm:block" />
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="border-b border-gray-100 dark:border-slate-800 px-3 pb-3 pt-2">
                                    <p class="truncate text-sm font-semibold text-navy-900 dark:text-slate-100">{{ $user->name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400">NIP {{ $user->nip ?? '-' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <x-badge color="brand">{{ $user->roleLabel() }}</x-badge>
                                        @if ($user->unit)
                                            <x-badge color="navy">{{ $user->unit->name }}</x-badge>
                                        @endif
                                    </div>
                                </div>
                                <div class="pt-1.5">
                                    <x-dropdown-link :href="route('profile.edit')">
                                        <x-icon name="user" class="h-4 w-4 text-gray-400 dark:text-slate-500" /> Profil Saya
                                    </x-dropdown-link>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm text-red-600 dark:text-red-400 transition hover:bg-red-50 dark:hover:bg-red-500/15">
                                            <x-icon name="logout" class="h-4 w-4" /> Keluar
                                        </button>
                                    </form>
                                </div>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>

                {{-- Page heading --}}
                @if ($title || isset($header))
                    <header class="vt-header mx-auto w-full max-w-7xl px-4 pt-5 sm:px-6 lg:px-8 lg:pt-6">
                        @isset($header)
                            {{ $header }}
                        @else
                            @if ($back)
                                <a href="{{ $back }}" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-slate-400 transition hover:text-brand-700 dark:hover:text-brand-300">
                                    <x-icon name="arrow-left" class="h-4 w-4" /> Kembali
                                </a>
                            @endif
                            <div class="flex flex-wrap items-end justify-between gap-4">
                                <div class="min-w-0">
                                    <h1 class="text-2xl font-bold tracking-tight text-navy-900 dark:text-slate-100">{{ $title }}</h1>
                                    @if ($subtitle)
                                        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">{{ $subtitle }}</p>
                                    @endif
                                </div>
                                @isset($actions)
                                    <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                                @endisset
                            </div>
                        @endisset
                    </header>
                @endif

                <!-- Page Content -->
                <main class="vt-content mx-auto w-full max-w-7xl flex-1 px-4 pb-8 pt-5 sm:px-6 lg:px-8">
                    {{ $slot }}
                </main>

                <footer class="mx-auto mt-auto w-full max-w-7xl px-4 py-5 text-xs text-gray-400 dark:text-slate-500 sm:px-6 lg:px-8">
                    © {{ now()->year }} RSU Syifa Medika · Sistem Informasi Mutu (SIMUTU)
                </footer>
            </div>
        </div>
    </body>
</html>

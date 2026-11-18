<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#172a46">

        <title>SIMUTU · RSU Syifa Medika</title>
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

        <div class="flex min-h-screen bg-gray-50 dark:bg-slate-950">
            {{-- Identity panel (desktop): logo and name only. --}}
            <aside class="relative hidden w-[46%] max-w-2xl flex-col justify-between overflow-hidden bg-navy-900 p-12 text-white dark:border-e dark:border-slate-800 dark:bg-slate-900 lg:flex">
                {{-- Soft brand glows (magenta and green) behind the content. --}}
                <div class="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-40 -left-24 h-96 w-96 rounded-full bg-accent-500/20 blur-3xl" aria-hidden="true"></div>

                <div class="relative flex items-center gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white p-2 shadow-lg">
                        <x-application-logo class="h-full w-full" />
                    </span>
                    <p class="text-lg font-bold leading-tight">RSU Syifa Medika</p>
                </div>

                <div class="relative">
                    <p class="text-5xl font-bold tracking-tight">SIMUTU</p>
                    <p class="mt-2 text-navy-200">Sistem Informasi Mutu</p>
                    <span class="mt-6 flex h-1 w-20 overflow-hidden rounded-full">
                        <span class="flex-1 bg-brand-500"></span>
                        <span class="flex-1 bg-accent-500"></span>
                    </span>
                </div>

                <span aria-hidden="true"></span>
            </aside>

            {{-- Form panel --}}
            <div class="relative flex min-w-0 flex-1 flex-col">
                <x-theme-toggle class="absolute end-4 top-4 sm:end-6 sm:top-6" />

                <main class="flex flex-1 flex-col items-center justify-center px-4 py-14 sm:px-6">
                    <div class="w-full max-w-sm">
                        {{-- Phones and tablets: the identity above the card. --}}
                        <div class="mb-6 flex items-center justify-center gap-3 lg:hidden">
                            <x-application-logo class="h-12 w-12" />
                            <div class="leading-tight">
                                <p class="text-base font-bold text-navy-900 dark:text-slate-100">SIMUTU</p>
                                <p class="text-xs text-gray-500 dark:text-slate-400">RSU Syifa Medika</p>
                            </div>
                        </div>

                        <div class="card p-6 sm:p-8">
                            {{ $slot }}
                        </div>
                    </div>
                </main>

                <footer class="pb-[max(1.25rem,env(safe-area-inset-bottom))] text-center text-xs text-gray-400 dark:text-slate-500">
                    © {{ now()->year }} RSU Syifa Medika
                </footer>
            </div>
        </div>
    </body>
</html>

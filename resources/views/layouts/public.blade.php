<!DOCTYPE html>
{{--
    Public pages (no login): the patient satisfaction survey. No navigation,
    no links into SIMUTU. Column layout: header, content (grows), footer at
    the bottom of short pages and after the content of long ones.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#172a46">
        <meta name="robots" content="noindex">

        <title>@yield('title', 'Survei Kepuasan Pasien') · RSU Syifa Medika</title>
        <link rel="icon" type="image/png" href="{{ asset('images/favicon-64.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <x-theme-script />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div id="page-progress" class="page-progress"></div>
        <x-toasts />

        <div class="flex min-h-screen flex-col bg-gray-50 dark:bg-slate-950">
            <header class="border-b border-gray-200/80 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
                <div class="mx-auto flex w-full max-w-2xl items-center gap-3 px-4 py-3 sm:px-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-gray-200 dark:ring-slate-700">
                        <x-application-logo class="h-full w-full" />
                    </span>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="truncate text-sm font-bold text-navy-900 dark:text-slate-100">RSU Syifa Medika</p>
                        <p class="truncate text-xs text-gray-500 dark:text-slate-400">Banjarbaru</p>
                    </div>
                    <x-theme-toggle />
                </div>
            </header>

            <main class="mx-auto w-full max-w-2xl flex-1 px-4 py-5 sm:px-6 sm:py-8">
                @yield('content')
            </main>

            <footer class="mx-auto w-full max-w-2xl px-4 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-2 text-center text-xs text-gray-400 dark:text-slate-500 sm:px-6">
                © {{ now()->year }} RSU Syifa Medika Banjarbaru
            </footer>
        </div>
    </body>
</html>

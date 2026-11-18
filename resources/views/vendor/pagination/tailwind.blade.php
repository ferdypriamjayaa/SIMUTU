@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navigasi halaman" class="flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-sm text-gray-500 dark:text-slate-400">
            Menampilkan
            <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $paginator->firstItem() }}</span>–<span class="font-semibold text-navy-900 dark:text-slate-100">{{ $paginator->lastItem() }}</span>
            dari <span class="font-semibold text-navy-900 dark:text-slate-100">{{ $paginator->total() }}</span> data
        </p>

        <div class="flex items-center gap-1">
            @php($base = 'inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-2.5 text-sm font-medium transition')

            @if ($paginator->onFirstPage())
                <span class="{{ $base }} cursor-not-allowed text-gray-300 dark:text-slate-600" aria-disabled="true" aria-label="Sebelumnya">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $base }} text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-navy-900 dark:hover:text-slate-100" aria-label="Sebelumnya">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                </a>
            @endif

            {{-- Page numbers are hidden on phones; prev/next and the summary are enough there. --}}
            <span class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $base }} text-gray-400 dark:text-slate-500">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="{{ $base }} bg-brand-600 text-white shadow-sm">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="{{ $base }} text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-navy-900 dark:hover:text-slate-100" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            <span class="px-2 text-sm text-gray-500 dark:text-slate-400 sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $base }} rotate-180 text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-800 hover:text-navy-900 dark:hover:text-slate-100" aria-label="Berikutnya">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                </a>
            @else
                <span class="{{ $base }} rotate-180 cursor-not-allowed text-gray-300 dark:text-slate-600" aria-disabled="true" aria-label="Berikutnya">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                </span>
            @endif
        </div>
    </nav>
@endif

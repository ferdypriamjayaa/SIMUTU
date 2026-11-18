{{-- Units an HRIS placement is mapped to, as removable chips. $mapped: Collection<HrisUnitMapping> with unit loaded. --}}
@if ($mapped->isNotEmpty())
    <ul class="mt-3 flex flex-wrap gap-2" aria-label="Unit yang dipetakan">
        @foreach ($mapped as $mapping)
            <li class="inline-flex items-center gap-1 rounded-full bg-accent-50 py-0.5 pe-1 ps-3 text-xs font-medium text-accent-700 ring-1 ring-inset ring-accent-600/20 dark:bg-accent-500/15 dark:text-accent-300 dark:ring-accent-400/30">
                {{ $mapping->unit?->name }}
                <form method="POST" action="{{ route('hris-mappings.destroy', $mapping) }}" class="inline-flex"
                    onsubmit="return confirm(@js('Hapus pemetaan ke unit '.$mapping->unit?->name.'?'))">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="flex h-6 w-6 items-center justify-center rounded-full transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40 dark:hover:bg-red-500/15 dark:hover:text-red-400"
                        aria-label="Hapus pemetaan ke unit {{ $mapping->unit?->name }}" title="Hapus">
                        <x-icon name="x" class="h-3.5 w-3.5" />
                    </button>
                </form>
            </li>
        @endforeach
    </ul>
@endif

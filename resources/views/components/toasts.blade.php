{{--
    Session feedback as toasts: fixed position, so showing or hiding them never
    moves the page. Success closes itself; errors stay until dismissed.
--}}
@php
    $messages = array_filter([
        'success' => session('success'),
        'error' => session('error'),
        'status' => in_array(session('status'), ['profile-updated', 'password-updated'], true) ? 'Perubahan berhasil disimpan.' : null,
    ]);
@endphp

<div aria-live="polite" class="pointer-events-none fixed inset-x-4 top-[4.5rem] z-[70] flex flex-col items-end gap-3 sm:inset-x-auto sm:right-6 sm:top-20 sm:w-full sm:max-w-sm">
    @foreach ($messages as $type => $message)
        @php($isError = $type === 'error')
        <div x-data="{ show: false }"
            x-init="$nextTick(() => show = true); {{ $isError ? '' : 'setTimeout(() => show = false, 5000)' }}"
            x-show="show" x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-4"
            x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            role="{{ $isError ? 'alert' : 'status' }}"
            class="pointer-events-auto w-full overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-raised {{ $isError ? 'border-red-200 dark:border-red-500/30' : 'border-accent-200 dark:border-accent-500/30' }}">
            <div class="flex items-start gap-3 p-4">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                    'bg-red-50 dark:bg-red-500/15 text-red-600 dark:text-red-400' => $isError,
                    'bg-accent-50 dark:bg-accent-500/15 text-accent-600 dark:text-accent-300' => ! $isError,
                ])>
                    <x-icon :name="$isError ? 'x-circle' : 'check-circle'" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                    <p class="text-sm font-semibold text-navy-900 dark:text-slate-100">{{ $isError ? 'Tidak dapat diproses' : 'Berhasil' }}</p>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-slate-300">{{ $message }}</p>
                </div>
                <button type="button" @click="show = false" class="btn-icon -m-1 h-8 w-8" aria-label="Tutup">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>
            <div @class(['h-1', 'bg-red-500' => $isError, 'bg-accent-500' => ! $isError])></div>
        </div>
    @endforeach

    {{-- Toasts raised by scripts without a page load: window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success'|'error', message } })) --}}
    <div x-data="{ items: [], next: 0 }" class="contents"
        @toast.window="
            const item = { id: ++next, error: $event.detail.type === 'error', message: $event.detail.message, show: false };
            items.push(item);
            $nextTick(() => { items.find((t) => t.id === item.id).show = true });
            if (! item.error) setTimeout(() => { const t = items.find((t) => t.id === item.id); if (t) t.show = false }, 5000);
        ">
        <template x-for="item in items" :key="item.id">
            <div x-show="item.show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-4"
                x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                :role="item.error ? 'alert' : 'status'"
                class="pointer-events-auto w-full overflow-hidden rounded-xl border bg-white dark:bg-slate-900 shadow-raised"
                :class="item.error ? 'border-red-200 dark:border-red-500/30' : 'border-accent-200 dark:border-accent-500/30'">
                <div class="flex items-start gap-3 p-4">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                        :class="item.error ? 'bg-red-50 dark:bg-red-500/15 text-red-600 dark:text-red-400' : 'bg-accent-50 dark:bg-accent-500/15 text-accent-600 dark:text-accent-300'">
                        <span x-show="item.error" class="flex"><x-icon name="x-circle" class="h-5 w-5" /></span>
                        <span x-show="! item.error" class="flex"><x-icon name="check-circle" class="h-5 w-5" /></span>
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="text-sm font-semibold text-navy-900 dark:text-slate-100" x-text="item.error ? 'Tidak dapat diproses' : 'Berhasil'"></p>
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-slate-300" x-text="item.message"></p>
                    </div>
                    <button type="button" @click="item.show = false" class="btn-icon -m-1 h-8 w-8" aria-label="Tutup">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
                <div class="h-1" :class="item.error ? 'bg-red-500' : 'bg-accent-500'"></div>
            </div>
        </template>
    </div>
</div>

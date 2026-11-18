@props([
    'name',
    'id' => null,
    // Local source: [['value' => …, 'label' => …, 'description' => ?, 'group' => ?], …]
    'options' => null,
    // Remote source: search URL (JSON, see ObservationLookupController) and extra query parameters.
    'url' => null,
    'params' => [],
    // Current choice, rendered by the server (also after a failed save): ['value' => …, 'label' => …].
    'selected' => null,
    // Extra choice pinned below the list, e.g. "Lainnya — ketik manual": ['value' => …, 'label' => …].
    'manual' => null,
    'placeholder' => 'Pilih…',
    'searchPlaceholder' => 'Ketik untuk mencari…',
    'emptyText' => 'Tidak ada pilihan.',
    'required' => false,
    'error' => null,
])

{{--
    Searchable dropdown (resources/js/combobox.js). The value is submitted in a
    hidden input named $name. The slot sits inside the component, so it can
    react to the choice (e.g. show the manual text input: x-show="isManual").
--}}
@php
    $id ??= $name;
    $errorKey = $error ?? $name;
    $invalid = $errors->has($errorKey);
    $config = [
        'id' => $id,
        'options' => $options === null ? null : array_values($options),
        'url' => $url,
        'params' => (object) $params,
        'selected' => $selected,
        'manual' => $manual,
        'placeholder' => $placeholder,
        'emptyText' => $emptyText,
        'invalid' => $invalid,
    ];
@endphp

<div x-data="combobox(@js($config))" data-combobox="{{ $id }}" {{ $attributes->class(['relative space-y-2']) }}>
    <input type="hidden" name="{{ $name }}" value="{{ $selected['value'] ?? '' }}" x-bind:value="value" />

    @if ($required)
        {{-- Mirror of the value for the browser's required check; never focused or announced. --}}
        <input type="text" tabindex="-1" aria-hidden="true" class="sr-only" required
            value="{{ $selected['value'] ?? '' }}" x-bind:value="value" x-on:invalid="onInvalid($event)" />
    @endif

    <button type="button" id="{{ $id }}" x-ref="trigger"
        role="combobox" aria-haspopup="listbox" aria-controls="{{ $id }}-listbox"
        aria-expanded="false" x-bind:aria-expanded="open.toString()"
        @if ($invalid) aria-invalid="true" @endif
        x-bind:aria-invalid="invalid ? 'true' : null"
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="show()"
        x-on:keydown.arrow-up.prevent="show()"
        @class(['form-control combobox-trigger', 'form-control-invalid' => $invalid])
        x-bind:class="{ 'form-control-invalid': invalid, 'combobox-trigger-open': open }">
        <span @class(['truncate', 'text-gray-400 dark:text-slate-500' => ! $selected])
            x-bind:class="selected ? 'text-gray-900 dark:text-slate-100' : 'text-gray-400 dark:text-slate-500'"
            x-text="selected ? selected.label : placeholder">{{ $selected['label'] ?? $placeholder }}</span>
        <x-icon name="chevron-down" class="combobox-chevron h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200 dark:text-slate-500" x-bind:class="open && 'rotate-180'" />
    </button>

    {{ $slot }}

    {{-- Teleported: a scrolling modal never cuts the list off, and it sits above the modal. --}}
    <template x-teleport="body">
        <div id="{{ $id }}-panel" x-show="open" x-cloak
            x-on:keydown.escape.stop.prevent="hide()"
            x-transition:enter="transition ease-out duration-150 motion-reduce:transition-none"
            x-transition:enter-start="opacity-0 scale-[0.98]"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100 motion-reduce:transition-none"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-[0.98]"
            x-bind:style="panelStyle"
            x-bind:class="placement === 'top' ? 'origin-bottom' : 'origin-top'"
            class="combobox-panel fixed z-[70] flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-raised dark:border-slate-700 dark:bg-slate-800">
            <div class="border-b border-gray-100 p-2 dark:border-slate-700">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                    <input type="search" id="{{ $id }}-search" x-model="query" autocomplete="off" spellcheck="false"
                        placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}"
                        aria-controls="{{ $id }}-listbox"
                        x-bind:aria-activedescendant="active >= 0 ? '{{ $id }}-option-' + active : null"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="chooseActive()"
                        x-on:keydown.tab="hide()"
                        class="h-10 w-full rounded-lg border border-gray-200 bg-gray-50 pl-9 pr-9 text-base text-gray-900 sm:text-sm placeholder:text-gray-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15 dark:border-slate-600 dark:bg-slate-900/60 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-brand-400 dark:focus:bg-slate-900" />
                    <span x-show="loading" x-cloak class="spinner absolute right-3 top-1/2 -mt-2 h-4 w-4 text-brand-600 dark:text-brand-300" role="status" aria-label="Memuat…"></span>
                </div>
            </div>

            <ul id="{{ $id }}-listbox" role="listbox" aria-labelledby="{{ $id }}" x-on:scroll.passive="onListScroll($event)"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain py-1 text-sm">
                <template x-for="(item, index) in items" :key="item.value">
                    <li role="presentation">
                        <p x-show="groupStart(index)" x-text="item.group"
                            class="sticky top-0 z-10 bg-gray-50/95 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 backdrop-blur dark:bg-slate-900/90 dark:text-slate-400"></p>
                        <div role="option" x-bind:id="'{{ $id }}-option-' + index"
                            x-bind:aria-selected="(item.value === value).toString()"
                            x-on:click="choose(item)" x-on:mousemove="active = index"
                            class="mx-1 flex cursor-pointer items-start gap-2 rounded-lg px-2.5 py-2"
                            x-bind:class="active === index ? 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-100' : 'text-gray-700 dark:text-slate-200'">
                            <span class="min-w-0 flex-1">
                                <span class="block" x-bind:class="item.value === value && 'font-semibold'" x-text="item.label"></span>
                                <span x-show="item.description" x-text="item.description" class="block text-xs text-gray-500 dark:text-slate-400"></span>
                            </span>
                            <x-icon name="check" x-show="item.value === value" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-300" />
                        </div>
                    </li>
                </template>

                {{-- Status rows --}}
                <li x-show="loading && items.length === 0" class="space-y-2 px-3 py-2" aria-hidden="true">
                    <span class="block h-3 w-3/4 animate-pulse rounded bg-gray-100 dark:bg-slate-700"></span>
                    <span class="block h-3 w-1/2 animate-pulse rounded bg-gray-100 dark:bg-slate-700"></span>
                    <span class="block h-3 w-2/3 animate-pulse rounded bg-gray-100 dark:bg-slate-700"></span>
                </li>
                <li x-show="! loading && ! error && items.length === 0" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-slate-400">
                    <x-icon name="search" class="mx-auto mb-1.5 h-5 w-5 text-gray-300 dark:text-slate-600" />
                    <span x-text="emptyText"></span>
                </li>
                <li x-show="error" x-cloak class="px-3 py-3 text-sm text-red-600 dark:text-red-400">
                    <span x-text="error"></span>
                    <button type="button" x-on:click="retry()" class="ml-1 font-medium underline">Coba lagi</button>
                </li>
                <li x-show="nextPage && ! error" x-cloak class="px-2 py-1">
                    <button type="button" x-on:click="loadMore()" x-bind:disabled="loading"
                        class="w-full rounded-lg px-2.5 py-2 text-xs font-medium text-brand-700 hover:bg-gray-50 disabled:opacity-60 dark:text-brand-300 dark:hover:bg-slate-700/60"
                        x-text="loading ? 'Memuat…' : 'Muat lebih banyak'"></button>
                </li>
            </ul>

            @if ($manual)
                <div class="border-t border-gray-100 p-1 dark:border-slate-700">
                    <div role="option" x-bind:id="'{{ $id }}-option-' + items.length"
                        x-bind:aria-selected="isManual.toString()"
                        x-on:click="choose(manual)" x-on:mousemove="active = items.length"
                        class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm"
                        x-bind:class="active === items.length ? 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-100' : 'text-gray-700 dark:text-slate-200'">
                        <x-icon name="pencil" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                        <span class="flex-1" x-bind:class="isManual && 'font-semibold'">{{ $manual['label'] }}</span>
                        <x-icon name="check" x-show="isManual" class="h-4 w-4 text-brand-600 dark:text-brand-300" />
                    </div>
                </div>
            @endif
        </div>
    </template>
</div>

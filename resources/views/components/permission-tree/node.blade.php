@props(['node', 'depth' => 0, 'mode' => 'role', 'editable' => true, 'on' => [], 'state' => []])

{{-- One folder of the permission tree: its own permissions (files), then its sub-folders. --}}
@php($key = $node['key'])
@php($indent = 'padding-inline-start: '.($depth * 1.25 + 0.25).'rem')
@php($leafIndent = 'padding-inline-start: '.(($depth + 1) * 1.25 + 0.25).'rem')

<li role="treeitem" :aria-expanded="(!! open[@js($key)]).toString()" data-node="{{ $key }}">
    <div class="flex items-center gap-2 rounded-lg py-1.5 pe-2 hover:bg-gray-50 dark:hover:bg-slate-800/60" style="{{ $indent }}">
        <button type="button" @click="toggleOpen(@js($key))" class="rounded p-0.5 text-gray-400 hover:text-navy-900 dark:text-slate-500 dark:hover:text-slate-100"
            :aria-label="(open[@js($key)] ? 'Tutup ' : 'Buka ') + @js($node['label'])">
            <x-icon name="lucide-chevron-right" class="h-4 w-4 transition-transform duration-200 motion-reduce:transition-none" x-bind:class="open[{{ Js::from($key) }}] && 'rotate-90'" />
        </button>

        <input type="checkbox" class="form-checkbox-base" data-folder="{{ $key }}"
            aria-label="Pilih semua izin {{ $node['label'] }}"
            x-effect="$el.indeterminate = state(@js($key)) === 'some'; $el.checked = state(@js($key)) === 'all'"
            @change="toggleNode(@js($key)); $el.checked = state(@js($key)) === 'all'"
            :disabled="nodeLocked(@js($key))">

        <x-icon name="lucide-folder" class="h-4 w-4 text-amber-500 dark:text-amber-400" x-show="! open[{{ Js::from($key) }}]" />
        <x-icon name="lucide-folder-open" class="h-4 w-4 text-amber-500 dark:text-amber-400" x-show="open[{{ Js::from($key) }}]" x-cloak />

        <button type="button" @click="toggleOpen(@js($key))" class="min-w-0 flex-1 truncate text-start font-medium text-navy-900 dark:text-slate-100">
            {{ $node['label'] }}
        </button>
        <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-slate-400" x-text="count(@js($key))"></span>
    </div>

    <div class="grid grid-rows-[0fr] transition-[grid-template-rows] duration-200 ease-out data-[open=true]:grid-rows-[1fr] motion-reduce:transition-none"
        :data-open="(!! open[@js($key)]).toString()" :inert="! open[@js($key)]" data-open="false" inert>
        <div class="min-h-0 overflow-hidden">
            <ul role="group">
                @foreach ($node['permissions'] ?? [] as $permission => $label)
                    <li role="treeitem" data-permission="{{ $permission }}" class="flex items-center gap-2 rounded-lg py-1 pe-2 hover:bg-gray-50 dark:hover:bg-slate-800/60" style="{{ $leafIndent }}">
                        <span class="w-5 shrink-0"></span>
                        <input type="checkbox" id="perm-{{ $mode }}-{{ $permission }}" class="form-checkbox-base"
                            @if ($mode === 'role' && $editable) name="permissions[]" value="{{ $permission }}" @endif
                            @checked(in_array($permission, $on, true))
                            :checked="isOn(@js($permission))"
                            @change="toggle(@js($permission)); $el.checked = isOn(@js($permission))"
                            :disabled="! canSet(@js($permission), ! isOn(@js($permission)))">
                        <x-icon name="lucide-file" class="h-4 w-4 text-gray-400 dark:text-slate-500" />
                        <label for="perm-{{ $mode }}-{{ $permission }}" class="min-w-0 flex-1 cursor-pointer">
                            <span class="text-navy-900 dark:text-slate-100">{{ $label }}</span>
                        </label>

                        @if ($mode === 'user')
                            @if ($editable)
                                <input type="hidden" name="overrides[{{ $permission }}]" value="{{ $state[$permission] ?? 'inherit' }}" :value="ov[@js($permission)]">
                                <button type="button" x-show="['added', 'restricted'].includes(status(@js($permission)))" x-cloak
                                    @click="reset(@js($permission))" class="rounded p-0.5 text-gray-400 hover:text-navy-900 dark:hover:text-slate-100"
                                    title="Kembalikan ke izin jabatan" aria-label="Kembalikan {{ $label }} ke izin jabatan">
                                    <x-icon name="lucide-rotate-ccw" class="h-3.5 w-3.5" />
                                </button>
                            @endif
                            <span class="badge shrink-0" x-show="status(@js($permission))" x-cloak
                                :class="{
                                    'bg-navy-50 dark:bg-navy-400/15 text-navy-700 dark:text-navy-200 ring-navy-600/20 dark:ring-navy-300/30': status(@js($permission)) === 'inherited',
                                    'bg-accent-50 dark:bg-accent-500/15 text-accent-700 dark:text-accent-300 ring-accent-600/20 dark:ring-accent-400/30': status(@js($permission)) === 'added',
                                    'bg-red-50 dark:bg-red-500/15 text-red-700 dark:text-red-300 ring-red-600/15 dark:ring-red-400/30': status(@js($permission)) === 'restricted',
                                    'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 ring-brand-600/20 dark:ring-brand-400/30': status(@js($permission)) === 'system',
                                }"
                                x-text="{ inherited: 'Dari jabatan', added: 'Ditambahkan', restricted: 'Dibatasi', system: 'Sistem' }[status(@js($permission))] ?? ''"></span>
                        @endif
                    </li>
                @endforeach

                @foreach ($node['children'] ?? [] as $child)
                    <x-permission-tree.node :node="$child" :depth="$depth + 1" :mode="$mode" :editable="$editable" :on="$on" :state="$state" />
                @endforeach
            </ul>
        </div>
    </div>
</li>

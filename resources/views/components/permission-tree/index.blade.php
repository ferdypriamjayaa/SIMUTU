@props(['mode' => 'role', 'editable' => true, 'on' => [], 'state' => []])

{{--
    Folder-style permission tree. The caller provides x-data="permissionTree({...})"
    (resources/js/permission-tree.js) on an ancestor element.
    mode "role": checkboxes submit permissions[]; mode "user": overrides[...].
    $on: permissions shown as checked before Alpine starts.
--}}
<div {{ $attributes->class('space-y-2') }}>
    <div class="flex flex-wrap items-center justify-end gap-3 text-xs">
        <button type="button" @click="expandAll(true)" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Buka semua</button>
        <button type="button" @click="expandAll(false)" class="text-gray-500 hover:underline dark:text-slate-400">Tutup semua</button>
    </div>

    <ul role="tree" aria-label="Izin" class="rounded-xl border border-gray-200 p-1.5 text-sm dark:border-slate-800">
        @foreach (App\Support\Permissions::TREE as $node)
            <x-permission-tree.node :node="$node" :depth="0" :mode="$mode" :editable="$editable" :on="$on" :state="$state" />
        @endforeach
    </ul>
</div>

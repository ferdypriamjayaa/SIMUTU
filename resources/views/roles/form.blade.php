@php
    $editing = $role->exists;
    $checked = old('permissions', $granted);
@endphp

<x-app-layout :title="$editing ? 'Edit Jabatan' : 'Tambah Jabatan'"
    :subtitle="$editing ? $role->name : null" :back="$editing ? route('roles.show', $role) : route('roles.index')">
    <div class="mx-auto max-w-4xl space-y-6">
        <x-flash />

        <form method="POST" action="{{ $editing ? route('roles.update', $role) : route('roles.store') }}" class="card card-body space-y-8">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <fieldset class="space-y-5">
                <legend class="mb-4 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                    <span class="h-4 w-1 rounded-full bg-brand-500"></span> Jabatan
                </legend>

                <x-form.field label="Nama jabatan" for="name" required>
                    <x-form.input name="name" :value="$role->name" maxlength="100" placeholder="mis. Kepala Ruangan" required autofocus />
                </x-form.field>

                <label class="flex items-center gap-3 text-sm text-navy-900 dark:text-slate-100">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="form-checkbox-base" @checked(old('is_active', $role->is_active))>
                    <span class="font-medium">Aktif</span>
                </label>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="mb-1 flex items-center gap-3 text-sm font-semibold text-navy-900 dark:text-slate-100">
                    <span class="h-4 w-1 rounded-full bg-brand-500"></span> Izin
                </legend>
                <x-input-error :messages="$errors->get('permissions')" />
                <x-input-error :messages="$errors->get('permissions.*')" />

                <div x-data="permissionTree(@js([
                    'mode' => 'role',
                    'checked' => array_values($checked),
                    'grantable' => array_values(array_diff(App\Support\Permissions::all(), $ungrantable)),
                    'leaves' => App\Support\Permissions::leavesByNode(),
                    'open' => array_fill_keys(array_column(App\Support\Permissions::TREE, 'key'), true),
                ]))">
                    <x-permission-tree mode="role" :on="$checked" />
                    @if ($ungrantable !== [])
                        <p class="form-hint mt-2">Izin yang tidak Anda miliki tidak dapat ditambahkan (kotak centang nonaktif), tetapi dapat dilepas.</p>
                    @endif
                </div>
            </fieldset>

            <x-form.actions :cancel="$editing ? route('roles.show', $role) : route('roles.index')" />
        </form>
    </div>
</x-app-layout>

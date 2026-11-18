<x-app-layout title="Profil Saya">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-card title="Informasi Profil" icon="user">
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card title="Ubah Kata Sandi" icon="key">
            @include('profile.partials.update-password-form')
        </x-card>

        <x-card title="Hapus Akun" icon="trash">
            @include('profile.partials.delete-user-form')
        </x-card>
    </div>
</x-app-layout>

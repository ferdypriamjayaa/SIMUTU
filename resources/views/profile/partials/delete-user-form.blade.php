<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-slate-300">
        Setelah akun dihapus, Anda tidak dapat login kembali. Akun yang sudah memiliki data observasi harus dinonaktifkan oleh administrator.
    </p>

    <x-button variant="secondary" type="button" icon="trash" class="text-red-600 dark:text-red-400 hover:border-red-300 dark:hover:border-red-500/50 hover:bg-red-50 dark:hover:bg-red-500/15 hover:text-red-700 dark:hover:text-red-300"
        x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
        Hapus Akun
    </x-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <div class="flex items-start gap-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 dark:bg-red-500/15 text-red-600 dark:text-red-400">
                    <x-icon name="warning" class="h-5 w-5" />
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-navy-900 dark:text-slate-100">Yakin ingin menghapus akun Anda?</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">Masukkan kata sandi untuk mengonfirmasi penghapusan akun secara permanen.</p>
                </div>
            </div>

            <x-form.field label="Kata Sandi" for="password" bag="userDeletion" error="password" class="mt-6">
                <x-form.input type="password" name="password" placeholder="Kata sandi"
                    :class="$errors->userDeletion->has('password') ? 'form-control-invalid' : ''" />
            </x-form.field>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-danger-button>Hapus Akun</x-danger-button>
            </div>
        </form>
    </x-modal>
</div>

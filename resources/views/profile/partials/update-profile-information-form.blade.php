<form id="send-verification" method="post" action="{{ route('verification.send') }}">
    @csrf
</form>

<form method="post" action="{{ route('profile.update') }}" class="space-y-5">
    @csrf
    @method('patch')

    <dl class="grid grid-cols-1 gap-4 rounded-xl bg-navy-50/60 dark:bg-slate-800/50 p-4 text-sm ring-1 ring-navy-100 dark:ring-slate-800 sm:grid-cols-3">
        <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400">NIP</dt>
            <dd class="mt-0.5 font-mono font-semibold text-navy-900 dark:text-slate-100">{{ $user->nip ?? '-' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400">Peran</dt>
            <dd class="mt-0.5 font-semibold text-navy-900 dark:text-slate-100">{{ $user->roleLabel() }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-slate-400">Unit</dt>
            <dd class="mt-0.5 font-semibold text-navy-900 dark:text-slate-100">{{ $user->unit?->name ?? '-' }}</dd>
        </div>
        <p class="text-xs text-gray-500 dark:text-slate-400 sm:col-span-3">NIP, peran, dan unit hanya dapat diubah oleh administrator.</p>
    </dl>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.field label="Nama" for="name" required>
            <x-form.input name="name" :value="$user->name" required autocomplete="name" />
        </x-form.field>

        <x-form.field label="Email" for="email" optional>
            <x-form.input type="email" name="email" :value="$user->email" autocomplete="email" />
        </x-form.field>
    </div>

    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && $user->email && ! $user->hasVerifiedEmail())
        <x-alert type="warning">
            Alamat email Anda belum terverifikasi.
            <button form="send-verification" class="link">Kirim ulang email verifikasi.</button>
            @if (session('status') === 'verification-link-sent')
                <p class="mt-1 font-medium">Tautan verifikasi baru telah dikirim ke email Anda.</p>
            @endif
        </x-alert>
    @endif

    <x-form.actions submit="Simpan Profil" />
</form>

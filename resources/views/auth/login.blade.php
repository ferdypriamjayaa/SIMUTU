<x-guest-layout>
    <h1 class="mb-6 text-xl font-bold tracking-tight text-navy-900 dark:text-slate-100">Masuk</h1>

    <!-- Session Status -->
    <x-auth-session-status class="mb-5" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- NIP -->
        <x-form.field label="NIP" for="nip">
            <div class="relative">
                <x-icon name="user" class="pointer-events-none absolute start-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <x-form.input name="nip" class="ps-11" required autofocus autocomplete="username"
                    inputmode="numeric" maxlength="30" placeholder="Masukkan NIP" />
            </div>
        </x-form.field>

        <!-- Password -->
        <x-form.field label="Kata Sandi" for="password" x-data="{ visible: false }">
            <div class="relative">
                <x-icon name="key" class="pointer-events-none absolute start-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <input id="password" name="password" type="password" :type="visible ? 'text' : 'password'"
                    required autocomplete="current-password" placeholder="Masukkan kata sandi"
                    @class(['form-control ps-11 pe-20', 'form-control-invalid' => $errors->has('password')])>
                <button type="button" @click="visible = ! visible"
                    class="absolute end-2 top-1/2 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-semibold text-gray-500 dark:text-slate-400 hover:text-navy-900 dark:hover:text-slate-100"
                    x-text="visible ? 'Sembunyikan' : 'Tampilkan'">Tampilkan</button>
            </div>
        </x-form.field>

        <div class="flex items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-slate-300">
                <input id="remember_me" type="checkbox" name="remember" class="form-checkbox-base">
                Ingat saya
            </label>

            @if (Route::has('password.request'))
                <a class="link text-sm" href="{{ route('password.request') }}">Lupa kata sandi?</a>
            @endif
        </div>

        <x-button class="w-full" icon="logout" loading-text="Memeriksa…">Masuk</x-button>
    </form>

</x-guest-layout>

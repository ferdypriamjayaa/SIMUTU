<form method="post" action="{{ route('password.update') }}" class="space-y-5">
    @csrf
    @method('put')

    <x-form.field label="Kata Sandi Saat Ini" for="update_password_current_password" bag="updatePassword" error="current_password" required>
        <x-form.input type="password" name="current_password" id="update_password_current_password" autocomplete="current-password"
            :class="$errors->updatePassword->has('current_password') ? 'form-control-invalid' : ''" />
    </x-form.field>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <x-form.field label="Kata Sandi Baru" for="update_password_password" bag="updatePassword" error="password" required>
            <x-form.input type="password" name="password" id="update_password_password" autocomplete="new-password"
                :class="$errors->updatePassword->has('password') ? 'form-control-invalid' : ''" />
        </x-form.field>

        <x-form.field label="Konfirmasi Kata Sandi Baru" for="update_password_password_confirmation" bag="updatePassword" error="password_confirmation" required>
            <x-form.input type="password" name="password_confirmation" id="update_password_password_confirmation" autocomplete="new-password"
                :class="$errors->updatePassword->has('password_confirmation') ? 'form-control-invalid' : ''" />
        </x-form.field>
    </div>

    <x-form.actions submit="Ubah Kata Sandi" />
</form>

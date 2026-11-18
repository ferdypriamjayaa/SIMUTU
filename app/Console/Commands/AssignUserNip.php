<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Assigns a login NIP to an existing account. Accounts created before NIP
 * login was introduced cannot sign in until they have one.
 *
 *   php artisan simutu:assign-nip                     list accounts without NIP
 *   php artisan simutu:assign-nip admin@example.com 1987001
 */
class AssignUserNip extends Command
{
    protected $signature = 'simutu:assign-nip
        {user? : ID atau email akun}
        {nip? : NIP baru (angka saja)}';

    protected $description = 'Tetapkan NIP untuk akun yang sudah ada, atau tampilkan akun yang belum memiliki NIP';

    public function handle(): int
    {
        if ($this->argument('user') === null) {
            return $this->listMissing();
        }

        $identifier = (string) $this->argument('user');
        $user = User::where('email', $identifier)
            ->orWhere(fn ($q) => ctype_digit($identifier) ? $q->whereKey((int) $identifier) : $q->whereRaw('1 = 0'))
            ->first();

        if (! $user) {
            $this->error("Akun [{$identifier}] tidak ditemukan.");

            return self::FAILURE;
        }

        $nip = trim((string) ($this->argument('nip') ?? $this->ask('NIP')));

        $validator = Validator::make(['nip' => $nip], [
            'nip' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/', Rule::unique('users', 'nip')->ignore($user->id)],
        ], [
            'nip.regex' => 'NIP hanya boleh berisi angka.',
        ], ['nip' => 'NIP']);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('nip'));

            return self::FAILURE;
        }

        $user->update(['nip' => $nip]);

        $this->info("NIP {$nip} ditetapkan untuk {$user->name} (ID {$user->id}).");

        return self::SUCCESS;
    }

    private function listMissing(): int
    {
        $users = User::whereNull('nip')->orderBy('id')->get(['id', 'name', 'email', 'role']);

        if ($users->isEmpty()) {
            $this->info('Semua akun sudah memiliki NIP.');

            return self::SUCCESS;
        }

        $this->warn('Akun berikut belum memiliki NIP dan belum dapat login:');
        $this->table(['ID', 'Nama', 'Email', 'Jabatan'], $users->map->only(['id', 'name', 'email', 'role']));
        $this->line('Gunakan: php artisan simutu:assign-nip <id|email> <nip>');

        return self::SUCCESS;
    }
}

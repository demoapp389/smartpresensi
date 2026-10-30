<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sp:create-admin
                            {--name= : Nama tampilan administrator}
                            {--email= : Email login administrator}
                            {--password= : Kata sandi administrator (minimal 8 karakter)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membuat atau memperbarui akun administrator pertama (dipakai wizard instalasi)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = trim((string) $this->option('name')) ?: 'Administrator Sekolah';
        $email = trim((string) $this->option('email'));
        $password = (string) $this->option('password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->info("Akun administrator '{$user->email}' siap digunakan.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
                            {name? : The user name}
                            {email? : The user email}
                            {--password= : The user password}';

    protected $description = 'Create a new application user from the command line';

    public function handle(): int
    {
        $name = trim((string) ($this->argument('name') ?: $this->ask('Name')));
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));
        $password = (string) ($this->option('password') ?: $this->promptForPassword());

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info('User created successfully.');
        $this->table(
            ['ID', 'Name', 'Email'],
            [[$user->id, $user->name, $user->email]]
        );

        return self::SUCCESS;
    }

    protected function promptForPassword(): string
    {
        while (true) {
            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Confirm password');

            if ($password === '') {
                $this->error('Password is required.');
                continue;
            }

            if ($password !== $confirmation) {
                $this->error('Passwords do not match.');
                continue;
            }

            return $password;
        }
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_interactively(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Name', 'Admin User')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'secret-password')
            ->expectsQuestion('Confirm password', 'secret-password')
            ->expectsOutput('User created successfully.')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Admin User', $user->name);
        $this->assertTrue(Hash::check('secret-password', (string) $user->password));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_user_and_token(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@test.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_with_wrong_password_fails_with_generic_message(): void
    {
        User::factory()->create(['email' => 'a@test.com']);

        $this->postJson('/api/login', ['email' => 'a@test.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.email.0', 'Email or password is incorrect.');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'off@test.com']);

        $this->postJson('/api/login', ['email' => 'off@test.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account has been deactivated.');
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'x@test.com', 'password' => 'nope']);
        }

        $this->postJson('/api/login', ['email' => 'x@test.com', 'password' => 'nope'])
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('bookpilot')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}

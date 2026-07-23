<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_and_update_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'email' => 'new@test.com',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'wrong-password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
    }
}

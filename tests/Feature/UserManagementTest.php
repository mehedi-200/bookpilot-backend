<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_staff_cannot_access_user_management(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_search_and_filter_users(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Karim Ahmed']);
        User::factory()->count(3)->create();

        $this->actingAs($admin)->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'meta' => ['current_page', 'total']]]);

        $this->actingAs($admin)->getJson('/api/users?q=Karim')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Karim Ahmed')
            ->assertJsonPath('data.meta.total', 1);

        $this->actingAs($admin)->getJson('/api/users?role=admin')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_admin_can_create_staff_with_role(): void
    {
        $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'New Staff',
            'email' => 'staff@test.com',
            'password' => 'secret-123',
            'role' => 'staff',
        ])->assertStatus(201)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('users', ['email' => 'staff@test.com', 'role' => 'staff']);
    }

    public function test_admin_can_update_user_and_change_role(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create();

        $this->actingAs($admin)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => 'admin',
        ])->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'staff',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['active']]);
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create();
        $staff->createToken('bookpilot');

        $this->actingAs($admin)->patchJson("/api/users/{$staff->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertSame(0, $staff->tokens()->count());
    }
}

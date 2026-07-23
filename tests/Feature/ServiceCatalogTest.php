<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_list_services(): void
    {
        Service::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_list_supports_search_and_active_filter(): void
    {
        Service::factory()->create(['name' => 'Oil Change']);
        Service::factory()->inactive()->create(['name' => 'Old Package']);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/services?q=oil')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.name', 'Oil Change');

        $this->actingAs($admin)->getJson('/api/services?active=0')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.name', 'Old Package');
    }

    public function test_admin_can_create_update_toggle_and_delete_a_service(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin)->postJson('/api/services', [
            'name' => 'Full Service',
            'duration_minutes' => 60,
            'price' => 120.50,
        ])->assertStatus(201)
            ->assertJsonPath('data.active', true)
            ->json('data.id');

        $this->actingAs($admin)->putJson("/api/services/{$id}", [
            'name' => 'Full Service Plus',
            'duration_minutes' => 90,
            'price' => 150,
        ])->assertOk()->assertJsonPath('data.name', 'Full Service Plus');

        $this->actingAs($admin)->patchJson("/api/services/{$id}/toggle-active")
            ->assertOk()->assertJsonPath('data.active', false);

        $this->actingAs($admin)->deleteJson("/api/services/{$id}")->assertOk();

        // Soft delete: row is kept for booking history.
        $this->assertSoftDeleted('services', ['id' => $id]);
    }

    public function test_staff_cannot_write_services(): void
    {
        $staff = User::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs($staff)->postJson('/api/services', [
            'name' => 'X', 'duration_minutes' => 30, 'price' => 10,
        ])->assertStatus(403);

        $this->actingAs($staff)->deleteJson("/api/services/{$service->id}")->assertStatus(403);
    }

    public function test_validation_rejects_bad_duration_and_price(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/services', [
            'name' => 'Bad', 'duration_minutes' => 2, 'price' => -5,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['duration_minutes', 'price']]);
    }
}

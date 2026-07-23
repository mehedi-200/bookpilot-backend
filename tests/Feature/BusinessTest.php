<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
    }

    public function test_authenticated_user_gets_business_with_setup_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/business')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'business' => ['id', 'name', 'timezone', 'auto_confirm'],
                    'setup_state' => ['business_configured', 'hours_set', 'has_services', 'widget_embedded'],
                ],
            ])
            ->assertJsonPath('data.setup_state.hours_set', true)
            ->assertJsonPath('data.setup_state.has_services', false);
    }

    public function test_widget_key_is_hidden_from_staff_and_visible_to_admin(): void
    {
        $staff = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff)->getJson('/api/business')
            ->assertOk()
            ->assertJsonMissingPath('data.business.widget_key');

        $this->actingAs($admin)->getJson('/api/business')
            ->assertOk()
            ->assertJsonStructure(['data' => ['business' => ['widget_key']]]);
    }

    public function test_admin_can_update_business_and_slug_follows_name(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/business', [
            'name' => "Rahim's Garage",
            'phone' => '01712-345678',
            'email' => null,
            'address' => null,
            'timezone' => 'Asia/Dhaka',
            'auto_confirm' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', "Rahim's Garage")
            ->assertJsonPath('data.auto_confirm', true);

        $this->assertSame('rahims-garage', Business::current()->slug);
    }

    public function test_staff_cannot_update_business(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->putJson('/api/business', [
            'name' => 'Hacked',
            'timezone' => 'UTC',
            'auto_confirm' => false,
        ])->assertStatus(403);
    }

    public function test_invalid_timezone_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/business', [
            'name' => 'Biz',
            'timezone' => 'Mars/Olympus',
            'auto_confirm' => false,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['timezone']]);
    }

    public function test_regenerating_widget_key_invalidates_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $oldKey = Business::current()->widget_key;

        $response = $this->actingAs($admin)->postJson('/api/business/widget-key/regenerate')
            ->assertOk();

        $newKey = $response->json('data.widget_key');
        $this->assertNotSame($oldKey, $newKey);
        $this->assertStringStartsWith('bp_live_', $newKey);
        $this->assertSame(40, strlen($newKey));
    }

    public function test_setup_state_reflects_services(): void
    {
        $admin = User::factory()->admin()->create();
        Service::factory()->create();

        $this->actingAs($admin)->getJson('/api/business')
            ->assertJsonPath('data.setup_state.has_services', true);
    }
}

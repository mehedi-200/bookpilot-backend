<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_crud_flow(): void
    {
        $user = $this->user();

        $id = $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Rahim Uddin',
            'phone' => '017 1234-5678',
            'email' => 'rahim@test.com',
        ])->assertStatus(201)
            ->assertJsonPath('data.phone', '01712345678') // stored normalized
            ->json('data.id');

        $this->actingAs($user)->getJson("/api/customers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Rahim Uddin')
            ->assertJsonPath('data.bookings_count', 0);

        $this->actingAs($user)->putJson("/api/customers/{$id}", [
            'name' => 'Rahim Uddin',
            'phone' => '01712345678',
            'notes' => 'Prefers mornings',
        ])->assertOk()->assertJsonPath('data.notes', 'Prefers mornings');
    }

    public function test_search_matches_partial_name_and_partial_phone(): void
    {
        Customer::factory()->create(['name' => 'Karim Ahmed', 'phone' => '01811111111']);
        Customer::factory()->create(['name' => 'Fatema Begum', 'phone' => '01922222222']);

        $user = $this->user();

        $this->actingAs($user)->getJson('/api/customers?q=kari')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.name', 'Karim Ahmed');

        // Partial phone, formatted differently than stored
        $this->actingAs($user)->getJson('/api/customers?q=0192-222')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.name', 'Fatema Begum');
    }

    public function test_duplicate_phone_returns_rich_422_payload(): void
    {
        $existing = Customer::factory()->create(['name' => 'Rahim Uddin', 'phone' => '01712345678']);

        $this->actingAs($this->user())->postJson('/api/customers', [
            'name' => 'Someone Else',
            'phone' => '+880 1712 345678', // same number, international form
        ])->assertStatus(422)
            ->assertJsonPath('errors.existing_customer.id', $existing->id)
            ->assertJsonPath('errors.existing_customer.name', 'Rahim Uddin');
    }

    public function test_update_cannot_steal_another_customers_phone(): void
    {
        Customer::factory()->create(['phone' => '01711111111']);
        $customer = Customer::factory()->create(['phone' => '01722222222']);

        $this->actingAs($this->user())->putJson("/api/customers/{$customer->id}", [
            'name' => $customer->name,
            'phone' => '017-1111 1111',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['existing_customer']]);
    }

    public function test_lookup_finds_exact_phone_in_any_format(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678']);

        $this->actingAs($this->user())->getJson('/api/customers/lookup?phone=%2B8801712345678')
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);

        $this->actingAs($this->user())->getJson('/api/customers/lookup?phone=01799999999')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_find_or_create_by_phone_is_idempotent_across_formats(): void
    {
        $service = app(CustomerService::class);

        $first = $service->findOrCreateByPhone('Rahim', '+880 1712-345678');
        $second = $service->findOrCreateByPhone('Rahim Uddin', '01712345678');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Customer::count());
    }

    public function test_creating_over_a_soft_deleted_customer_restores_them(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678']);
        $customer->delete();

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/customers', [
            'name' => 'Rahim Restored',
            'phone' => '01712345678',
        ])->assertStatus(201)->assertJsonPath('data.id', $customer->id);

        $this->assertNull($customer->fresh()->deleted_at);
    }

    public function test_only_admin_can_delete_and_delete_is_soft(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->user())->deleteJson("/api/customers/{$customer->id}")
            ->assertStatus(403);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/customers/{$customer->id}")
            ->assertOk();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }
}

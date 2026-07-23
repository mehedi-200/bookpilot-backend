<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): Customer
    {
        $customer = Customer::factory()->create([
            'name' => 'Rahim Uddin',
            'phone' => '01712345678',
        ]);

        Booking::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => Service::factory()->create(['name' => 'Full Service'])->id,
            'reference' => 'BP-2026-0042',
        ]);

        Conversation::start()->update([
            'customer_id' => $customer->id,
            'last_activity_at' => now(),
        ]);

        return $customer;
    }

    public function test_a_name_finds_all_three_kinds_at_once(): void
    {
        $this->seedData();

        $data = $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=Rahim')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['bookings']);
        $this->assertCount(1, $data['customers']);
        $this->assertCount(1, $data['conversations']);
        $this->assertSame(3, $data['total']);
        $this->assertSame('BP-2026-0042', $data['bookings'][0]['reference']);
    }

    public function test_a_partial_phone_matches_however_it_is_typed(): void
    {
        $this->seedData();
        $user = User::factory()->create();

        foreach (['0171-234', '+880 1712 345', '01712345678'] as $term) {
            $data = $this->actingAs($user)->getJson('/api/search?q='.urlencode($term))->json('data');

            $this->assertCount(1, $data['customers'], "failed for term: {$term}");
        }
    }

    public function test_a_booking_reference_finds_its_booking(): void
    {
        $this->seedData();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=BP-2026-0042')
            ->assertOk()
            ->assertJsonPath('data.bookings.0.reference', 'BP-2026-0042');
    }

    public function test_one_character_returns_nothing_rather_than_everything(): void
    {
        $this->seedData();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=R')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_each_group_is_capped_so_the_dropdown_stays_readable(): void
    {
        Customer::factory()->count(9)->create(['name' => 'Testerson']);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=Testerson')
            ->assertOk()
            ->assertJsonCount(5, 'data.customers');
    }

    public function test_search_requires_a_login(): void
    {
        $this->getJson('/api/search?q=Rahim')->assertStatus(401);
    }
}

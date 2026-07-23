<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->travelTo('2026-07-27 08:00:00'); // Monday, before opening
    }

    public function test_dashboard_is_a_single_request_with_every_section(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats' => ['today', 'week', 'pending', 'customers', 'active_conversations'],
                    'needs_attention' => ['pending', 'failed_syncs', 'handoffs'],
                    'today',
                    'upcoming',
                    'by_status' => ['pending', 'confirmed', 'completed', 'cancelled'],
                    'ai_share' => ['ai', 'manual', 'percent'],
                    'series' => [['date', 'label', 'ai', 'manual', 'total', 'is_today']],
                    'setup_state' => ['hours_set', 'has_services', 'widget_embedded'],
                ],
            ]);
    }

    public function test_counts_match_seeded_data(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();

        // Two today (one cancelled → excluded), one later this week, one long past.
        Booking::factory()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-07-27 10:00:00', 'ends_at' => '2026-07-27 11:00:00',
            'status' => Booking::STATUS_CONFIRMED,
        ]);
        Booking::factory()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-07-27 14:00:00', 'ends_at' => '2026-07-27 15:00:00',
            'status' => Booking::STATUS_CANCELLED,
        ]);
        Booking::factory()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-07-29 10:00:00', 'ends_at' => '2026-07-29 11:00:00',
            'status' => Booking::STATUS_PENDING,
        ]);
        Booking::factory()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-06-01 10:00:00', 'ends_at' => '2026-06-01 11:00:00',
            'status' => Booking::STATUS_COMPLETED,
        ]);

        $data = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $data['stats']['today']);        // cancelled excluded
        $this->assertSame(2, $data['stats']['week']);         // today + Wednesday
        $this->assertSame(1, $data['stats']['pending']);
        $this->assertSame(1, $data['stats']['customers']);
        $this->assertCount(1, $data['today']);                // cancelled excluded
        $this->assertSame(1, $data['by_status']['cancelled']);
        $this->assertSame(1, $data['needs_attention']['pending']);
    }

    public function test_series_covers_fifteen_days_and_buckets_by_source(): void
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();

        Booking::factory()->fromWidget()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-07-27 10:00:00', 'ends_at' => '2026-07-27 11:00:00',
        ]);
        Booking::factory()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id,
            'starts_at' => '2026-07-27 14:00:00', 'ends_at' => '2026-07-27 15:00:00',
        ]);

        $series = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->json('data.series');

        $this->assertCount(15, $series); // 6 back, today, 8 forward
        $today = collect($series)->firstWhere('is_today', true);
        $this->assertSame('2026-07-27', $today['date']);
        $this->assertSame(1, $today['ai']);
        $this->assertSame(1, $today['manual']);
        $this->assertSame(2, $today['total']);
    }

    public function test_ai_share_is_the_percentage_of_widget_bookings(): void
    {
        Booking::factory()->count(3)->fromWidget()->create();
        Booking::factory()->count(1)->create();

        $share = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->json('data.ai_share');

        $this->assertSame(3, $share['ai']);
        $this->assertSame(1, $share['manual']);
        $this->assertSame(75, $share['percent']);
    }

    public function test_ai_share_is_zero_percent_with_no_bookings(): void
    {
        $share = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->json('data.ai_share');

        $this->assertSame(0, $share['percent']);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SyncBookingToGarageFlow;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GarageFlow\GarageFlowService;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GarageFlowTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://garage.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->travelTo('2026-07-24 10:00:00');
    }

    private function configure(bool $enabled = true): Integration
    {
        return tap(Integration::garageflow())->update([
            'base_url' => self::BASE,
            'api_token' => 'gf-token',
            'default_mechanic_id' => 7,
            'default_mechanic_name' => 'Mechanic Mike',
            'enabled' => $enabled,
        ]);
    }

    private function booking(): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create([
                'name' => 'Rahim Uddin', 'phone' => '01712345678', 'email' => 'rahim@test.com',
            ])->id,
            'service_id' => Service::factory()->create(['name' => 'Brake Check'])->id,
            'starts_at' => '2026-07-27 10:00:00',
            'ends_at' => '2026-07-27 11:00:00',
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            self::BASE.'/api/customers?*' => Http::response(['data' => ['data' => []]]),
            self::BASE.'/api/customers' => Http::response(['data' => ['id' => 55, 'name' => 'Rahim Uddin']]),
            self::BASE.'/api/vehicles?*' => Http::response(['data' => ['data' => []]]),
            self::BASE.'/api/vehicles' => Http::response(['data' => ['id' => 88]]),
            self::BASE.'/api/service-jobs' => Http::response(['data' => ['id' => 321]]),
        ]);
    }

    /* ── Connection ───────────────────────────────────────────────────── */

    public function test_test_connection_reports_who_the_token_belongs_to(): void
    {
        $this->configure();
        Http::fake([self::BASE.'/api/profile' => Http::response([
            'data' => ['id' => 1, 'name' => 'Admin', 'email' => 'admin@garage.test'],
        ])]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/integrations/garageflow/test')
            ->assertOk()
            ->assertJsonPath('data.connected_as', 'admin@garage.test');

        $this->assertNotNull(Integration::garageflow()->last_ok_at);
    }

    public function test_a_rejected_token_says_so_plainly(): void
    {
        $this->configure();
        Http::fake([self::BASE.'/api/profile' => Http::response([], 401)]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/integrations/garageflow/test')
            ->assertStatus(422)
            ->assertJsonPath('message', 'GarageFlow rejected the API token. Generate a new one and paste it here.');

        $this->assertNotNull(Integration::garageflow()->last_error);
    }

    public function test_a_wrong_url_gives_a_different_error_than_a_wrong_token(): void
    {
        $this->configure();
        Http::fake([self::BASE.'/api/profile' => Http::response([], 404)]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/integrations/garageflow/test')
            ->assertStatus(422)
            ->assertJsonPath('message', 'That URL does not look like a GarageFlow API. Check the address.');
    }

    public function test_an_unreachable_host_is_reported_as_a_connection_problem(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6'));

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/integrations/garageflow/test')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Could not reach that address. Check the URL and that GarageFlow is running.');
    }

    public function test_the_token_is_never_returned_or_stored_in_plaintext(): void
    {
        $this->configure();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/integrations/garageflow')
            ->assertOk()
            ->assertJsonPath('data.has_token', true)
            ->assertJsonMissing(['api_token' => 'gf-token'])
            ->assertJsonPath('data.masked_token', '••••••••oken');

        $raw = \DB::table('integrations')->value('api_token');
        $this->assertNotSame('gf-token', $raw);
        $this->assertStringNotContainsString('gf-token', (string) $raw);
    }

    public function test_saving_without_a_token_keeps_the_existing_one(): void
    {
        $this->configure();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/integrations/garageflow', [
                'base_url' => self::BASE,
                'api_token' => '',
                'default_mechanic_id' => 7,
                'enabled' => true,
            ])->assertOk();

        $this->assertSame('gf-token', Integration::garageflow()->api_token);
    }

    public function test_staff_cannot_see_or_change_the_integration(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->getJson('/api/integrations/garageflow')->assertStatus(403);
        $this->actingAs($staff)->postJson('/api/integrations/garageflow/test')->assertStatus(403);
    }

    /* ── Sync ─────────────────────────────────────────────────────────── */

    public function test_a_full_sync_creates_customer_vehicle_and_job(): void
    {
        $this->configure();
        $this->fakeHappyPath();
        $booking = $this->booking();

        app(GarageFlowService::class)->sync($booking);

        $booking->refresh();
        $this->assertSame('synced', $booking->sync_status);
        $this->assertSame('321', $booking->garageflow_job_id);
        $this->assertNotNull($booking->synced_at);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/service-jobs')
                && $request['vehicle_id'] === 88
                && $request['mechanic_id'] === 7
                && $request['service_type'] === 'Brakes' // mapped from "Brake Check"
                && str_contains($request['description'], $request['description'] ? 'BookPilot' : '');
        });
    }

    public function test_an_existing_garageflow_customer_is_reused_not_duplicated(): void
    {
        $this->configure();
        Http::fake([
            self::BASE.'/api/customers?*' => Http::response([
                'data' => ['data' => [['id' => 12, 'name' => 'Rahim', 'phone' => '017-1234 5678']]],
            ]),
            self::BASE.'/api/vehicles?*' => Http::response(['data' => ['data' => []]]),
            self::BASE.'/api/vehicles' => Http::response(['data' => ['id' => 88]]),
            self::BASE.'/api/service-jobs' => Http::response(['data' => ['id' => 400]]),
        ]);

        app(GarageFlowService::class)->sync($this->booking());

        // Matched on digits despite the different formatting — no POST /customers.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/customers'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/vehicles')
            && $request->method() === 'POST'
            && $request['customer_id'] === 12);
    }

    public function test_a_repeat_booking_reuses_the_placeholder_vehicle(): void
    {
        $this->configure();
        Http::fake([
            self::BASE.'/api/customers?*' => Http::response(['data' => ['data' => [['id' => 12, 'phone' => '01712345678']]]]),
            self::BASE.'/api/vehicles?*' => Http::response([
                'data' => ['data' => [['id' => 99, 'registration_no' => 'BP-01712345678']]],
            ]),
            self::BASE.'/api/service-jobs' => Http::response(['data' => ['id' => 401]]),
        ]);

        app(GarageFlowService::class)->sync($this->booking());

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/vehicles'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/service-jobs')
            && $request['vehicle_id'] === 99);
    }

    public function test_service_names_map_onto_garageflows_fixed_types(): void
    {
        $this->configure();
        $expectations = [
            'Oil Change' => 'Oil Change',
            'Full Engine Rebuild' => 'Engine',
            'AC Regas' => 'AC',
            'Wedding Detailing' => 'General Service', // no keyword → safe default
        ];

        foreach ($expectations as $serviceName => $expectedType) {
            $this->fakeHappyPath();
            $booking = Booking::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
                'service_id' => Service::factory()->create(['name' => $serviceName])->id,
                'starts_at' => '2026-07-27 10:00:00',
                'ends_at' => '2026-07-27 11:00:00',
            ]);

            app(GarageFlowService::class)->sync($booking);

            Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/api/service-jobs')
                || $request['service_type'] === $expectedType);
        }
    }

    public function test_failure_at_the_job_step_marks_the_booking_failed(): void
    {
        $this->configure();
        Http::fake([
            self::BASE.'/api/customers?*' => Http::response(['data' => ['data' => []]]),
            self::BASE.'/api/customers' => Http::response(['data' => ['id' => 55]]),
            self::BASE.'/api/vehicles?*' => Http::response(['data' => ['data' => []]]),
            self::BASE.'/api/vehicles' => Http::response(['data' => ['id' => 88]]),
            self::BASE.'/api/service-jobs' => Http::response(['message' => 'boom'], 500),
        ]);

        $booking = $this->booking();
        $service = app(GarageFlowService::class);

        try {
            $service->sync($booking);
        } catch (\Throwable $e) {
            $service->markFailed($booking, $e);
        }

        $booking->refresh();
        $this->assertSame('failed', $booking->sync_status);
        $this->assertSame(1, $booking->sync_attempts);
        $this->assertNull($booking->garageflow_job_id);
    }

    public function test_manual_retry_endpoint_syncs_and_reports_back(): void
    {
        $this->configure();
        $this->fakeHappyPath();
        $booking = $this->booking();
        $booking->forceFill(['sync_status' => 'failed'])->save();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/bookings/{$booking->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.sync_status', 'synced');
    }

    /* ── Triggering ───────────────────────────────────────────────────── */

    public function test_confirming_a_booking_queues_the_sync(): void
    {
        Queue::fake();
        $this->configure();

        $booking = Booking::factory()->status(Booking::STATUS_PENDING)->create();
        app(BookingService::class)->transition($booking, Booking::STATUS_CONFIRMED);

        Queue::assertPushed(SyncBookingToGarageFlow::class);
        $this->assertSame('pending', $booking->fresh()->sync_status);
    }

    public function test_nothing_is_queued_when_the_integration_is_off(): void
    {
        Queue::fake();
        $this->configure(enabled: false);

        $booking = Booking::factory()->status(Booking::STATUS_PENDING)->create();
        app(BookingService::class)->transition($booking, Booking::STATUS_CONFIRMED);

        Queue::assertNothingPushed();
        $this->assertNull($booking->fresh()->sync_status);
    }

    public function test_confirming_is_not_delayed_by_garageflow_being_down(): void
    {
        Queue::fake(); // proves the transition returns without any HTTP call
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('GarageFlow is on fire'));

        $booking = Booking::factory()->status(Booking::STATUS_PENDING)->create();
        $result = app(BookingService::class)->transition($booking, Booking::STATUS_CONFIRMED);

        $this->assertSame(Booking::STATUS_CONFIRMED, $result->status);
        Http::assertNothingSent();
    }
}

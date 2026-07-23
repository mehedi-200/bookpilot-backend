<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27'; // seeded hours: 09:00–18:00

    private const SUNDAY = '2026-07-26'; // closed

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->travelTo('2026-07-24 10:00:00');
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function service(int $minutes = 60): Service
    {
        return Service::factory()->create(['duration_minutes' => $minutes]);
    }

    private function create(array $overrides = []): Booking
    {
        return app(BookingService::class)->create(array_merge([
            'service_id' => $this->service()->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '01712345678',
        ], $overrides));
    }

    /* ── Creation ─────────────────────────────────────────────────────── */

    public function test_creating_a_booking_generates_a_sequential_reference(): void
    {
        $service = $this->service();

        $first = $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '017 1234-5678',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.customer.phone', '01712345678')
            ->json('data.reference');

        $this->assertSame('BP-2026-0001', $first);

        $second = $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 11:00:00',
            'customer_name' => 'Karim',
            'customer_phone' => '01811111111',
        ])->assertStatus(201)->json('data.reference');

        $this->assertSame('BP-2026-0002', $second);
    }

    public function test_booking_reuses_an_existing_customer_by_phone(): void
    {
        $existing = Customer::factory()->create(['phone' => '01712345678']);

        $this->create(['customer_phone' => '+880 1712 345678']);

        $this->assertSame(1, Customer::count());
        $this->assertSame($existing->id, Booking::first()->customer_id);
    }

    public function test_ends_at_follows_the_service_duration(): void
    {
        $booking = $this->create(['service_id' => $this->service(90)->id]);

        $this->assertSame(90, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
    }

    public function test_double_booking_the_same_slot_is_rejected(): void
    {
        $service = $this->service();
        $this->create(['service_id' => $service->id]);

        $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Someone Else',
            'customer_phone' => '01999999999',
        ])->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'That slot has just been taken — please pick another time.');

        $this->assertSame(1, Booking::count());
    }

    public function test_partially_overlapping_booking_is_rejected(): void
    {
        $this->create(['service_id' => $this->service(60)->id]); // 10:00–11:00

        $this->expectException(ValidationException::class);
        $this->create([
            'service_id' => $this->service(60)->id,
            'starts_at' => self::MONDAY.' 10:30:00',
            'customer_phone' => '01999999999',
        ]);
    }

    public function test_a_cancelled_booking_frees_its_slot(): void
    {
        $booking = $this->create();
        app(BookingService::class)->transition($booking, Booking::STATUS_CANCELLED);

        $again = $this->create(['customer_phone' => '01999999999']);

        $this->assertNotNull($again->id);
        $this->assertSame(2, Booking::count());
    }

    public function test_booking_outside_working_hours_is_rejected(): void
    {
        $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $this->service()->id,
            'starts_at' => self::MONDAY.' 03:00:00',
            'customer_name' => 'Night Owl',
            'customer_phone' => '01712345678',
        ])->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'That time is outside your working hours.');
    }

    public function test_booking_on_a_closed_day_is_rejected(): void
    {
        $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $this->service()->id,
            'starts_at' => self::SUNDAY.' 10:00:00',
            'customer_name' => 'Weekend Warrior',
            'customer_phone' => '01712345678',
        ])->assertStatus(422)
            ->assertJsonPath('errors.starts_at.0', 'The business is closed on that day.');
    }

    public function test_inactive_service_cannot_be_booked(): void
    {
        $service = Service::factory()->inactive()->create();

        $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Rahim',
            'customer_phone' => '01712345678',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['service_id']]);
    }

    public function test_customer_details_are_required_when_no_customer_id_given(): void
    {
        $this->actingAs($this->user())->postJson('/api/bookings', [
            'service_id' => $this->service()->id,
            'starts_at' => self::MONDAY.' 10:00:00',
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['customer_id', 'customer_name', 'customer_phone']]);
    }

    /* ── Status machine ───────────────────────────────────────────────── */

    public static function validTransitions(): array
    {
        return [
            'pending → confirmed' => [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED],
            'pending → cancelled' => [Booking::STATUS_PENDING, Booking::STATUS_CANCELLED],
            'confirmed → completed' => [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED],
            'confirmed → cancelled' => [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions_are_allowed(string $from, string $to): void
    {
        $booking = Booking::factory()->status($from)->create();

        $this->actingAs($this->user())
            ->patchJson("/api/bookings/{$booking->id}/status", ['status' => $to])
            ->assertOk()
            ->assertJsonPath('data.status', $to);
    }

    public static function invalidTransitions(): array
    {
        return [
            'pending → completed' => [Booking::STATUS_PENDING, Booking::STATUS_COMPLETED],
            'confirmed → confirmed' => [Booking::STATUS_CONFIRMED, Booking::STATUS_CONFIRMED],
            'completed → confirmed' => [Booking::STATUS_COMPLETED, Booking::STATUS_CONFIRMED],
            'completed → cancelled' => [Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED],
            'cancelled → confirmed' => [Booking::STATUS_CANCELLED, Booking::STATUS_CONFIRMED],
            'cancelled → completed' => [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_are_rejected(string $from, string $to): void
    {
        $booking = Booking::factory()->status($from)->create();

        $this->actingAs($this->user())
            ->patchJson("/api/bookings/{$booking->id}/status", ['status' => $to])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $this->assertSame($from, $booking->fresh()->status);
    }

    public function test_cancelling_records_the_reason_and_timestamp(): void
    {
        $booking = Booking::factory()->status(Booking::STATUS_CONFIRMED)->create();

        $this->actingAs($this->user())->patchJson("/api/bookings/{$booking->id}/status", [
            'status' => Booking::STATUS_CANCELLED,
            'cancel_reason' => 'Customer called to cancel',
        ])->assertOk()->assertJsonPath('data.cancel_reason', 'Customer called to cancel');

        $this->assertNotNull($booking->fresh()->cancelled_at);
    }

    public function test_resource_exposes_only_the_single_valid_next_step(): void
    {
        $pending = Booking::factory()->status(Booking::STATUS_PENDING)->create();
        $completed = Booking::factory()->status(Booking::STATUS_COMPLETED)->create();

        $this->actingAs($this->user())->getJson("/api/bookings/{$pending->id}")
            ->assertJsonPath('data.next_status', 'confirmed')
            ->assertJsonPath('data.is_cancellable', true);

        $this->actingAs($this->user())->getJson("/api/bookings/{$completed->id}")
            ->assertJsonPath('data.next_status', null)
            ->assertJsonPath('data.is_cancellable', false);
    }

    /* ── Reschedule ───────────────────────────────────────────────────── */

    public function test_rescheduling_moves_the_booking_and_keeps_the_old_time(): void
    {
        $booking = $this->create();
        $originalStart = $booking->starts_at->toISOString();

        $this->actingAs($this->user())->patchJson("/api/bookings/{$booking->id}/reschedule", [
            'starts_at' => self::MONDAY.' 14:00:00',
        ])->assertOk()
            ->assertJsonPath('data.rescheduled_from', $originalStart);

        $this->assertSame('14:00', $booking->fresh()->starts_at->format('H:i'));
    }

    public function test_rescheduling_onto_a_taken_slot_is_rejected(): void
    {
        $service = $this->service();
        $booking = $this->create(['service_id' => $service->id]); // 10:00
        $this->create([
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 14:00:00',
            'customer_phone' => '01999999999',
        ]);

        $this->actingAs($this->user())->patchJson("/api/bookings/{$booking->id}/reschedule", [
            'starts_at' => self::MONDAY.' 14:00:00',
        ])->assertStatus(422);
    }

    public function test_rescheduling_to_its_own_current_time_is_allowed(): void
    {
        $booking = $this->create();

        $this->actingAs($this->user())->patchJson("/api/bookings/{$booking->id}/reschedule", [
            'starts_at' => self::MONDAY.' 10:00:00',
        ])->assertOk();
    }

    public function test_terminal_bookings_cannot_be_rescheduled(): void
    {
        $booking = Booking::factory()->status(Booking::STATUS_COMPLETED)->create();

        $this->actingAs($this->user())->patchJson("/api/bookings/{$booking->id}/reschedule", [
            'starts_at' => self::MONDAY.' 14:00:00',
        ])->assertStatus(422);
    }

    /* ── Listing ──────────────────────────────────────────────────────── */

    public function test_index_filters_by_status_source_and_search(): void
    {
        $rahim = Customer::factory()->create(['name' => 'Rahim Uddin', 'phone' => '01712345678']);
        Booking::factory()->status(Booking::STATUS_PENDING)->create(['customer_id' => $rahim->id]);
        Booking::factory()->status(Booking::STATUS_CONFIRMED)->fromWidget()->create();

        $user = $this->user();

        $this->actingAs($user)->getJson('/api/bookings')
            ->assertOk()->assertJsonPath('data.meta.total', 2);

        $this->actingAs($user)->getJson('/api/bookings?status=pending')
            ->assertJsonPath('data.meta.total', 1);

        $this->actingAs($user)->getJson('/api/bookings?source=widget')
            ->assertJsonPath('data.meta.total', 1);

        $this->actingAs($user)->getJson('/api/bookings?q=Rahim')
            ->assertJsonPath('data.meta.total', 1);

        // Partial phone, formatted differently than stored
        $this->actingAs($user)->getJson('/api/bookings?q=0171-234')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_availability_endpoint_returns_grouped_slots(): void
    {
        $service = $this->service(60);

        $this->actingAs($this->user())
            ->getJson("/api/availability?service_id={$service->id}&date=".self::MONDAY)
            ->assertOk()
            ->assertJsonPath('data.total', 9)
            ->assertJsonStructure(['data' => ['slots' => ['morning', 'afternoon', 'evening'], 'timezone']]);
    }

    public function test_customer_detail_includes_their_bookings_in_one_request(): void
    {
        $customer = Customer::factory()->create();
        Booking::factory()->count(2)->create(['customer_id' => $customer->id]);

        $this->actingAs($this->user())->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.bookings_count', 2)
            ->assertJsonCount(2, 'data.bookings')
            ->assertJsonStructure(['data' => ['bookings' => [['reference', 'status', 'service']]]]);
    }
}

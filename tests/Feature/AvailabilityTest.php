<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Business;
use App\Models\ClosedDate;
use App\Models\Customer;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Services\AvailabilityService;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // Seeded hours are Mon–Fri 09:00–18:00, Sat/Sun closed.
    private const MONDAY = '2026-07-27';

    private const SUNDAY = '2026-07-26';

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->availability = app(AvailabilityService::class);
        // Well before the test dates, so lead time never interferes by accident.
        $this->travelTo('2026-07-24 10:00:00');
    }

    private function service(int $minutes = 60): Service
    {
        return Service::factory()->create(['duration_minutes' => $minutes]);
    }

    private function book(string $startsAt, int $minutes, string $status = Booking::STATUS_CONFIRMED): Booking
    {
        return Booking::create([
            'reference' => 'BP-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory()->create()->id,
            'service_id' => $this->service()->id,
            'starts_at' => $startsAt,
            'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + $minutes * 60),
            'status' => $status,
        ]);
    }

    private function starts(array $result): array
    {
        return collect($result['slots'])->flatten(1)->pluck('label')->all();
    }

    public function test_normal_day_produces_slots_across_all_three_groups(): void
    {
        $result = $this->availability->slots($this->service(60), self::MONDAY);

        $this->assertSame(9, $result['total']); // 09:00 … 17:00
        $this->assertCount(3, $result['slots']['morning']);   // 9, 10, 11
        $this->assertCount(5, $result['slots']['afternoon']); // 12 … 16
        $this->assertCount(1, $result['slots']['evening']);   // 17
        $this->assertSame('9:00 AM', $result['slots']['morning'][0]['label']);
        $this->assertNull($result['closed_reason']);
    }

    public function test_closed_weekday_returns_no_slots(): void
    {
        $result = $this->availability->slots($this->service(), self::SUNDAY);

        $this->assertSame(0, $result['total']);
        $this->assertSame('Closed on this day', $result['closed_reason']);
    }

    public function test_closed_date_overrides_an_open_day(): void
    {
        ClosedDate::create(['date' => self::MONDAY, 'reason' => 'Eid']);

        $result = $this->availability->slots($this->service(), self::MONDAY);

        $this->assertSame(0, $result['total']);
        $this->assertSame('Closed on this date', $result['closed_reason']);
    }

    public function test_lead_time_hides_slots_that_are_too_soon(): void
    {
        // 09:00 on the day itself, 60-minute notice → first bookable slot is 10:00.
        $this->travelTo(self::MONDAY.' 09:00:00');

        $labels = $this->starts($this->availability->slots($this->service(60), self::MONDAY));

        $this->assertNotContains('9:00 AM', $labels);
        $this->assertContains('10:00 AM', $labels);
        $this->assertCount(8, $labels);
    }

    public function test_an_existing_booking_removes_exactly_its_slot(): void
    {
        $this->book(self::MONDAY.' 11:00:00', 60);

        $labels = $this->starts($this->availability->slots($this->service(60), self::MONDAY));

        $this->assertNotContains('11:00 AM', $labels);
        $this->assertContains('10:00 AM', $labels);
        $this->assertContains('12:00 PM', $labels);
        $this->assertCount(8, $labels);
    }

    public function test_a_long_booking_blocks_every_slot_it_spans(): void
    {
        $this->book(self::MONDAY.' 10:00:00', 180); // 10:00–13:00

        $labels = $this->starts($this->availability->slots($this->service(60), self::MONDAY));

        $this->assertNotContains('10:00 AM', $labels);
        $this->assertNotContains('11:00 AM', $labels);
        $this->assertNotContains('12:00 PM', $labels);
        $this->assertContains('1:00 PM', $labels);
        $this->assertCount(6, $labels);
    }

    public function test_back_to_back_bookings_do_not_swallow_the_adjacent_slot(): void
    {
        // Ends exactly when the 10:00 slot starts — touching, not overlapping.
        $this->book(self::MONDAY.' 09:00:00', 60);

        $labels = $this->starts($this->availability->slots($this->service(60), self::MONDAY));

        $this->assertNotContains('9:00 AM', $labels);
        $this->assertContains('10:00 AM', $labels);
        $this->assertCount(8, $labels);
    }

    public function test_cancelled_bookings_free_their_slot(): void
    {
        $this->book(self::MONDAY.' 11:00:00', 60, Booking::STATUS_CANCELLED);

        $labels = $this->starts($this->availability->slots($this->service(60), self::MONDAY));

        $this->assertContains('11:00 AM', $labels);
        $this->assertCount(9, $labels);
    }

    public function test_pending_bookings_hold_their_slot(): void
    {
        $this->book(self::MONDAY.' 11:00:00', 60, Booking::STATUS_PENDING);

        $this->assertNotContains(
            '11:00 AM',
            $this->starts($this->availability->slots($this->service(60), self::MONDAY))
        );
    }

    public function test_slot_size_follows_the_service_duration(): void
    {
        $labels = $this->starts($this->availability->slots($this->service(90), self::MONDAY));

        // 09:00, 10:30, 12:00, 13:30, 15:00, 16:30 (ends exactly at 18:00)
        $this->assertSame(
            ['9:00 AM', '10:30 AM', '12:00 PM', '1:30 PM', '3:00 PM', '4:30 PM'],
            $labels
        );
    }

    public function test_a_service_longer_than_the_day_yields_nothing(): void
    {
        $this->assertSame(0, $this->availability->slots($this->service(600), self::MONDAY)['total']);
    }

    public function test_slots_are_computed_in_the_business_timezone_and_returned_as_utc(): void
    {
        Business::current()->update(['timezone' => 'Asia/Dhaka']); // UTC+6
        WorkingHour::where('day_of_week', 1)->update(['close_time' => '23:00']);

        $result = $this->availability->slots($this->service(60), self::MONDAY);
        $evening = collect($result['slots']['evening']);

        $last = $evening->last();
        $this->assertSame('10:00 PM', $last['label']);
        // 22:00 in Dhaka is 16:00 UTC — proves the conversion, not just formatting.
        $this->assertStringStartsWith('2026-07-27T16:00:00', $last['start']);
        $this->assertSame('Asia/Dhaka', $result['timezone']);
    }

    public function test_rejection_reason_guards_hand_crafted_times(): void
    {
        $service = $this->service(60);

        $this->assertNull($this->availability->rejectionReason(
            $service, now()->parse(self::MONDAY.' 10:00:00'), Booking::SOURCE_MANUAL
        ));

        $this->assertSame(
            'That time is outside your working hours.',
            $this->availability->rejectionReason(
                $service, now()->parse(self::MONDAY.' 03:00:00'), Booking::SOURCE_MANUAL
            )
        );

        $this->assertSame(
            'The business is closed on that day.',
            $this->availability->rejectionReason(
                $service, now()->parse(self::SUNDAY.' 10:00:00'), Booking::SOURCE_MANUAL
            )
        );
    }

    public function test_lead_time_applies_to_widget_bookings_but_not_manual_ones(): void
    {
        $this->travelTo(self::MONDAY.' 09:30:00');
        $service = $this->service(60);
        $soon = now()->parse(self::MONDAY.' 10:00:00'); // 30 min away, lead time is 60

        $this->assertSame(
            'That time is too soon — please pick a later slot.',
            $this->availability->rejectionReason($service, $soon, Booking::SOURCE_WIDGET)
        );

        // Staff can still squeeze someone in by hand.
        $this->assertNull(
            $this->availability->rejectionReason($service, $soon, Booking::SOURCE_MANUAL)
        );
    }
}

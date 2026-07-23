<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GarageFlow\GarageFlowService;
use App\Services\NotificationService;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->travelTo('2026-07-24 10:00:00');
    }

    public function test_ai_bookings_notify_admins_and_staff_but_manual_ones_notify_nobody(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        app(BookingService::class)->create([
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'source' => Booking::SOURCE_WIDGET,
        ]);

        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame(1, Notification::where('user_id', $staff->id)->count());

        // A booking someone typed in themselves isn't news.
        app(BookingService::class)->create([
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 11:00:00',
            'customer_name' => 'Karim', 'customer_phone' => '01811111111',
        ]);

        $this->assertSame(2, Notification::count());
    }

    public function test_cancellations_notify_admins_only(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $booking = Booking::factory()->status(Booking::STATUS_CONFIRMED)->create();

        app(BookingService::class)->transition($booking, Booking::STATUS_CANCELLED);

        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame(0, Notification::where('user_id', $staff->id)->count());
    }

    public function test_a_handoff_notifies_the_team(): void
    {
        User::factory()->admin()->create();
        $conversation = Conversation::start();
        $conversation->update(['handoff_reason' => 'Wants a refund']);

        app(NotificationService::class)->handoff($conversation);

        $notification = Notification::first();
        $this->assertSame(Notification::TYPE_HANDOFF, $notification->type);
        $this->assertSame('Wants a refund', $notification->body);
        $this->assertSame($conversation->id, $notification->data['conversation_id']);
    }

    public function test_a_retry_storm_does_not_produce_duplicate_sync_notifications(): void
    {
        User::factory()->admin()->create();
        $booking = Booking::factory()->create();
        $service = app(GarageFlowService::class);

        // Three attempts of the same failing sync.
        foreach (range(1, 3) as $ignored) {
            $service->markFailed($booking, new \RuntimeException('down'));
        }

        $this->assertSame(1, Notification::where('type', Notification::TYPE_SYNC_FAILED)->count());

        // Once it's been read, a fresh failure is worth telling them about again.
        Notification::first()->update(['read_at' => now()]);
        $service->markFailed($booking, new \RuntimeException('down again'));

        $this->assertSame(2, Notification::where('type', Notification::TYPE_SYNC_FAILED)->count());
    }

    public function test_inactive_users_are_not_notified(): void
    {
        User::factory()->admin()->inactive()->create();
        $booking = Booking::factory()->status(Booking::STATUS_CONFIRMED)->create();

        app(BookingService::class)->transition($booking, Booking::STATUS_CANCELLED);

        $this->assertSame(0, Notification::count());
    }

    public function test_listing_counting_and_marking_read(): void
    {
        $user = User::factory()->admin()->create();

        foreach (range(1, 3) as $i) {
            Notification::create([
                'user_id' => $user->id, 'type' => Notification::TYPE_AI_BOOKING,
                'title' => "Booking {$i}",
            ]);
        }

        $this->actingAs($user)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        $this->actingAs($user)->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 3);

        $first = Notification::first();
        $this->actingAs($user)->patchJson("/api/notifications/{$first->id}/read")->assertOk();
        $this->actingAs($user)->getJson('/api/notifications/unread-count')
            ->assertJsonPath('data.count', 2);

        $this->actingAs($user)->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2);

        $this->actingAs($user)->getJson('/api/notifications/unread-count')
            ->assertJsonPath('data.count', 0);
    }

    public function test_you_cannot_read_someone_elses_notification(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $theirs->id, 'type' => Notification::TYPE_AI_BOOKING, 'title' => 'Theirs',
        ]);

        $this->actingAs($mine)->patchJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_notifications_carry_a_deep_link_target(): void
    {
        User::factory()->admin()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $booking = app(BookingService::class)->create([
            'service_id' => $service->id,
            'starts_at' => self::MONDAY.' 10:00:00',
            'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'source' => Booking::SOURCE_WIDGET,
        ]);

        $this->assertSame($booking->id, Notification::first()->data['booking_id']);
    }

    public function test_dashboard_reports_handoffs_needing_attention(): void
    {
        $user = User::factory()->admin()->create();
        Conversation::start()->update(['status' => Conversation::STATUS_HANDED_OFF]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.needs_attention.handoffs', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Services\Agent\AgentService;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27'; // seeded hours 09:00–18:00

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        $this->travelTo('2026-07-24 10:00:00');
        config(['bookpilot.anthropic_key' => 'test-key']);
    }

    /* ── Fake Claude responses ────────────────────────────────────────── */

    private function textReply(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ];
    }

    private function toolReply(string $name, array $input, string $id = 'toolu_1'): array
    {
        return [
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ];
    }

    private function agent(): AgentService
    {
        return app(AgentService::class);
    }

    /* ── The loop ─────────────────────────────────────────────────────── */

    public function test_plain_reply_is_returned_and_persisted(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->textReply('We open at 9am.'))]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'What time do you open?');

        $this->assertSame('We open at 9am.', $reply);
        $this->assertSame(2, $conversation->messages()->count()); // user + assistant
        $this->assertSame(
            20,
            $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->first()->output_tokens
        );
    }

    public function test_agent_chains_tools_then_answers(): void
    {
        $service = Service::factory()->create(['name' => 'Full Service', 'duration_minutes' => 60]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('list_services', [], 'toolu_a'))
                ->push($this->toolReply('check_availability', [
                    'service_id' => $service->id, 'date' => self::MONDAY,
                ], 'toolu_b'))
                ->push($this->textReply('I can do 9:00 AM or 10:00 AM on Monday — which suits?')),
        ]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'I need a full service Monday');

        $this->assertStringContainsString('9:00 AM', $reply);

        $toolMessages = $conversation->messages()->where('role', Message::ROLE_TOOL)->get();
        $this->assertSame(['list_services', 'check_availability'], $toolMessages->pluck('tool_name')->all());
        $this->assertTrue($toolMessages->last()->tool_result['available']);
    }

    public function test_agent_books_and_the_booking_is_linked_to_the_conversation(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('create_booking', [
                    'service_id' => $service->id,
                    'starts_at' => self::MONDAY.'T10:00:00Z',
                    'customer_name' => 'Rahim Uddin',
                    'customer_phone' => '01712345678',
                ]))
                ->push($this->textReply('Booked! Your reference is BP-2026-0001.')),
        ]);

        $conversation = Conversation::start();
        $this->agent()->handle($conversation, 'Book me Monday 10am, Rahim, 01712345678');

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertSame(Booking::SOURCE_WIDGET, $booking->source);
        $this->assertSame($conversation->id, $booking->conversation_id);
        $this->assertSame(Booking::STATUS_PENDING, $booking->status); // auto_confirm off by default

        // The chat now belongs to that customer.
        $this->assertSame($booking->customer_id, $conversation->fresh()->customer_id);
    }

    public function test_auto_confirm_setting_decides_the_booking_status(): void
    {
        Business::current()->update(['auto_confirm' => true]);
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('create_booking', [
                    'service_id' => $service->id,
                    'starts_at' => self::MONDAY.'T11:00:00Z',
                    'customer_name' => 'Karim',
                    'customer_phone' => '01811111111',
                ]))
                ->push($this->textReply('You are booked.')),
        ]);

        $this->agent()->handle(Conversation::start(), 'book it');

        $this->assertSame(Booking::STATUS_CONFIRMED, Booking::first()->status);
    }

    public function test_invalid_tool_input_is_reported_back_so_the_agent_can_recover(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                // 3am — outside working hours; the engine must refuse.
                ->push($this->toolReply('create_booking', [
                    'service_id' => $service->id,
                    'starts_at' => self::MONDAY.'T03:00:00Z',
                    'customer_name' => 'Night Owl',
                    'customer_phone' => '01712345678',
                ]))
                ->push($this->textReply('That time is not available — how about 10am?')),
        ]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'book 3am');

        $this->assertSame(0, Booking::count());
        $this->assertStringContainsString('not available', $reply);

        $toolResult = $conversation->messages()->where('role', Message::ROLE_TOOL)->first()->tool_result;
        $this->assertArrayHasKey('error', $toolResult);
    }

    public function test_unknown_tool_does_not_break_the_conversation(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('order_pizza', ['size' => 'large']))
                ->push($this->textReply('I can only help with bookings.')),
        ]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'order me a pizza');

        $this->assertSame('I can only help with bookings.', $reply);
        $this->assertStringContainsString(
            'Unknown tool',
            $conversation->messages()->where('role', Message::ROLE_TOOL)->first()->tool_result['error']
        );
    }

    public function test_iteration_cap_hands_off_instead_of_looping_forever(): void
    {
        config(['bookpilot.max_iterations' => 3]);
        Business::current()->update(['phone' => '01700000000']);

        // Always asks for another tool — never finishes.
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolReply('list_services', []))]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'hello');

        $this->assertStringContainsString('01700000000', $reply);
        $this->assertSame(Conversation::STATUS_HANDED_OFF, $conversation->fresh()->status);
    }

    public function test_api_failure_hands_off_with_a_friendly_message(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $conversation = Conversation::start();
        $reply = $this->agent()->handle($conversation, 'hello');

        $this->assertStringContainsString('follow up', $reply);
        $this->assertSame(Conversation::STATUS_HANDED_OFF, $conversation->fresh()->status);
        // The customer's message is still on record for the owner to read.
        $this->assertSame(1, $conversation->messages()->where('role', Message::ROLE_USER)->count());
    }

    public function test_handoff_tool_marks_the_conversation(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('handoff_to_human', ['reason' => 'Wants a refund']))
                ->push($this->textReply('Someone will call you back.')),
        ]);

        $conversation = Conversation::start();
        $this->agent()->handle($conversation, 'I want a refund');

        $this->assertSame(Conversation::STATUS_HANDED_OFF, $conversation->fresh()->status);
        $this->assertSame('Wants a refund', $conversation->fresh()->handoff_reason);
    }

    /* ── Guardrails ───────────────────────────────────────────────────── */

    public function test_cancel_tool_cannot_touch_another_persons_booking(): void
    {
        $mine = Customer::factory()->create(['phone' => '01712345678']);
        $theirs = Customer::factory()->create(['phone' => '01999999999']);
        $theirBooking = Booking::factory()->status(Booking::STATUS_CONFIRMED)
            ->create(['customer_id' => $theirs->id, 'reference' => 'BP-2026-0099']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('cancel_booking', ['reference' => 'BP-2026-0099']))
                ->push($this->textReply('I could not find that booking.')),
        ]);

        $conversation = Conversation::start();
        $conversation->update(['customer_id' => $mine->id]);

        $this->agent()->handle($conversation, 'cancel BP-2026-0099');

        $this->assertSame(Booking::STATUS_CONFIRMED, $theirBooking->fresh()->status);
        $this->assertStringContainsString(
            'No booking with that reference',
            $conversation->messages()->where('role', Message::ROLE_TOOL)->first()->tool_result['error']
        );
    }

    public function test_cancel_tool_works_for_your_own_booking(): void
    {
        $customer = Customer::factory()->create(['phone' => '01712345678']);
        $booking = Booking::factory()->status(Booking::STATUS_CONFIRMED)
            ->create(['customer_id' => $customer->id, 'reference' => 'BP-2026-0042']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('cancel_booking', ['reference' => 'bp-2026-0042']))
                ->push($this->textReply('Cancelled.')),
        ]);

        $conversation = Conversation::start();
        $conversation->update(['customer_id' => $customer->id]);

        $this->agent()->handle($conversation, 'cancel it');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_booking_actions_need_a_known_phone_first(): void
    {
        Booking::factory()->create(['reference' => 'BP-2026-0007']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->toolReply('cancel_booking', ['reference' => 'BP-2026-0007']))
                ->push($this->textReply('What phone number was it booked with?')),
        ]);

        $conversation = Conversation::start(); // anonymous
        $this->agent()->handle($conversation, 'cancel my booking');

        $this->assertStringContainsString(
            'phone number',
            $conversation->messages()->where('role', Message::ROLE_TOOL)->first()->tool_result['error']
        );
    }

    public function test_system_prompt_carries_services_hours_and_guardrails(): void
    {
        Service::factory()->create(['name' => 'Brake Check']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->textReply('hi'))]);

        $this->agent()->handle(Conversation::start(), 'hello');

        Http::assertSent(function ($request) {
            $system = $request['system'];

            return str_contains($system, 'Brake Check')
                && str_contains($system, 'Never invent or guess an available time')
                && str_contains($system, 'Monday: 09:00')
                && str_contains($system, 'Sunday: closed')
                && count($request['tools']) === 6;
        });
    }
}

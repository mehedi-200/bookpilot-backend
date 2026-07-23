<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Service;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
        config(['bookpilot.anthropic_key' => 'test-key']);
    }

    private function key(): string
    {
        return Business::current()->widget_key;
    }

    private function widget(array $headers = []): static
    {
        return $this->withHeaders(array_merge(['X-Widget-Key' => $this->key()], $headers));
    }

    private function fakeReply(string $text = 'Hello!'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
    }

    public function test_bootstrap_returns_what_the_widget_needs(): void
    {
        Service::factory()->create(['name' => 'Full Service']);

        $this->widget()->getJson('/api/widget/bootstrap')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['business' => ['name'], 'services', 'hours', 'greeting', 'quick_starts'],
            ])
            ->assertJsonPath('data.services.0.name', 'Full Service');
    }

    public function test_a_missing_or_wrong_key_is_rejected(): void
    {
        $this->getJson('/api/widget/bootstrap')->assertStatus(401);

        $this->withHeaders(['X-Widget-Key' => 'bp_live_totally_wrong'])
            ->getJson('/api/widget/bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_a_regenerated_key_invalidates_the_old_one(): void
    {
        $oldKey = $this->key();
        Business::current()->regenerateWidgetKey();

        $this->withHeaders(['X-Widget-Key' => $oldKey])
            ->getJson('/api/widget/bootstrap')
            ->assertStatus(401);
    }

    public function test_first_widget_call_marks_it_as_seen_for_the_setup_checklist(): void
    {
        $this->assertNull(Business::current()->widget_seen_at);

        $this->widget()->getJson('/api/widget/bootstrap')->assertOk();

        $this->assertNotNull(Business::current()->fresh()->widget_seen_at);
    }

    public function test_chat_starts_a_conversation_and_returns_a_resume_token(): void
    {
        $this->fakeReply('We open at 9am.');

        $response = $this->widget()->postJson('/api/widget/chat', ['message' => 'When do you open?'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'We open at 9am.')
            ->assertJsonPath('data.status', 'active');

        $token = $response->json('data.conversation_token');
        $this->assertSame(40, strlen($token));
        $this->assertDatabaseHas('conversations', ['token' => $token]);
    }

    public function test_passing_the_token_back_continues_the_same_conversation(): void
    {
        $this->fakeReply();

        $token = $this->widget()->postJson('/api/widget/chat', ['message' => 'hi'])
            ->json('data.conversation_token');

        $this->widget()->postJson('/api/widget/chat', [
            'message' => 'still here?',
            'conversation_token' => $token,
        ])->assertOk()->assertJsonPath('data.conversation_token', $token);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(4, Conversation::first()->messages()->count()); // 2 user + 2 assistant
    }

    public function test_an_unknown_token_starts_a_fresh_conversation_rather_than_failing(): void
    {
        $this->fakeReply();

        $this->widget()->postJson('/api/widget/chat', [
            'message' => 'hi',
            'conversation_token' => str_repeat('x', 40),
        ])->assertOk();

        $this->assertSame(1, Conversation::count());
    }

    public function test_chat_validates_the_message(): void
    {
        $this->widget()->postJson('/api/widget/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);

        $this->widget()->postJson('/api/widget/chat', ['message' => str_repeat('a', 1001)])
            ->assertStatus(422);
    }

    public function test_chat_returns_the_booking_when_the_agent_makes_one(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $this->travelTo('2026-07-24 10:00:00');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_booking',
                        'input' => [
                            'service_id' => $service->id,
                            'starts_at' => '2026-07-27T10:00:00Z',
                            'customer_name' => 'Rahim',
                            'customer_phone' => '01712345678',
                        ],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ])
                ->push([
                    'content' => [['type' => 'text', 'text' => 'All booked!']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]),
        ]);

        $this->widget()->postJson('/api/widget/chat', ['message' => 'book monday 10am'])
            ->assertOk()
            ->assertJsonPath('data.booking.reference', 'BP-2026-0001')
            ->assertJsonPath('data.booking.status', 'pending');
    }

    public function test_chat_returns_offered_slots_so_the_widget_can_show_chips(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $this->travelTo('2026-07-24 10:00:00');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'check_availability',
                        'input' => ['service_id' => $service->id, 'date' => '2026-07-27'],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ])
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Here are Monday’s times.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]),
        ]);

        $response = $this->widget()->postJson('/api/widget/chat', ['message' => 'monday please'])
            ->assertOk()
            ->assertJsonPath('data.slots.date', '2026-07-27');

        $this->assertSame('9:00 AM', $response->json('data.slots.morning.0.time'));
        $this->assertNotEmpty($response->json('data.slots.morning.0.starts_at'));
    }

    public function test_no_slots_are_returned_when_the_agent_did_not_look_any_up(): void
    {
        $this->fakeReply('We open at 9am.');

        $this->widget()->postJson('/api/widget/chat', ['message' => 'when do you open?'])
            ->assertOk()
            ->assertJsonPath('data.slots', null);
    }

    public function test_chat_is_rate_limited_per_conversation(): void
    {
        $this->fakeReply();

        $token = $this->widget()->postJson('/api/widget/chat', ['message' => 'hi'])
            ->json('data.conversation_token');

        for ($i = 0; $i < 10; $i++) {
            $this->widget()->postJson('/api/widget/chat', [
                'message' => "msg {$i}", 'conversation_token' => $token,
            ])->assertOk();
        }

        $this->widget()->postJson('/api/widget/chat', [
            'message' => 'one too many', 'conversation_token' => $token,
        ])->assertStatus(429)->assertJsonPath('success', false);
    }

    public function test_dashboard_can_read_conversations_but_the_public_cannot(): void
    {
        $this->fakeReply('Hi there');
        $this->widget()->postJson('/api/widget/chat', ['message' => 'hello'])->assertOk();

        $this->getJson('/api/conversations')->assertStatus(401);

        $this->actingAs(\App\Models\User::factory()->create())
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.preview', 'Hi there');

        $this->actingAs(\App\Models\User::factory()->create())
            ->getJson('/api/conversations/'.Conversation::first()->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.tokens_used', 15);
    }
}

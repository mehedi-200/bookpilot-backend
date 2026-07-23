<?php

namespace App\Services\Agent;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The booking clerk. Runs Claude in a tool-use loop until it produces a reply
 * for the customer, persisting every turn — including tool calls — so the
 * owner can read exactly what happened.
 */
class AgentService
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** How many past messages to replay as context. */
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly ToolRegistry $registry,
        private readonly NotificationService $notifications,
    ) {
    }

    public function handle(Conversation $conversation, string $userMessage): string
    {
        $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $userMessage,
        ]);

        $conversation->update(['last_activity_at' => now()]);

        $maxIterations = (int) config('bookpilot.max_iterations', 8);

        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $response = $this->claude->messages(
                    $this->systemPrompt($conversation),
                    $this->history($conversation),
                    $this->registry->definitions(),
                );

                $blocks = $response['content'] ?? [];
                $text = $this->textFrom($blocks);
                $toolCalls = $this->toolCallsFrom($blocks);

                $conversation->messages()->create([
                    'role' => Message::ROLE_ASSISTANT,
                    'content' => $text ?: null,
                    'tool_calls' => $toolCalls ?: null,
                    'input_tokens' => $response['usage']['input_tokens'] ?? null,
                    'output_tokens' => $response['usage']['output_tokens'] ?? null,
                ]);

                if ($toolCalls === []) {
                    return $text !== ''
                        ? $text
                        : $this->handoff($conversation, 'The assistant returned an empty reply.');
                }

                foreach ($toolCalls as $call) {
                    $result = $this->registry->execute($call['name'], $call['input'], $conversation);

                    $conversation->messages()->create([
                        'role' => Message::ROLE_TOOL,
                        'tool_use_id' => $call['id'],
                        'tool_name' => $call['name'],
                        'tool_result' => $result,
                    ]);
                }
            }

            // Went round in circles — better a human than an endless loop.
            return $this->handoff($conversation, 'The assistant could not resolve the request.');
        } catch (\Throwable $e) {
            Log::error('BookPilot agent failed', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);

            return $this->handoff($conversation, 'The assistant was unavailable.');
        }
    }

    /**
     * Never leave the customer stranded: mark the chat for a human, say so
     * plainly, and give them the phone number if we have one.
     */
    private function handoff(Conversation $conversation, string $reason): string
    {
        $business = Business::current();

        $conversation->update([
            'status' => Conversation::STATUS_HANDED_OFF,
            'handoff_reason' => $reason,
        ]);

        $reply = $business->phone
            ? "Sorry — I can't finish that right now. Please call us on {$business->phone} and we'll sort it out."
            : "Sorry — I can't finish that right now. Someone from our team will follow up with you shortly.";

        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => $reply,
        ]);

        // Someone is waiting — the team needs to know now, not tomorrow.
        $this->notifications->handoff($conversation);

        return $reply;
    }

    /** Rebuild the Anthropic message array from what we persisted. */
    private function history(Conversation $conversation): array
    {
        // reorder() replaces the relation's ascending sort — with a plain
        // latest() the window would silently take the OLDEST messages.
        $messages = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $out = [];
        $pendingResults = [];

        $flushResults = function () use (&$out, &$pendingResults) {
            if ($pendingResults !== []) {
                $out[] = ['role' => 'user', 'content' => $pendingResults];
                $pendingResults = [];
            }
        };

        foreach ($messages as $message) {
            if ($message->role === Message::ROLE_TOOL) {
                $pendingResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->tool_use_id,
                    'content' => json_encode($message->tool_result),
                ];

                continue;
            }

            $flushResults();

            if ($message->role === Message::ROLE_USER) {
                $out[] = ['role' => 'user', 'content' => $message->content ?? ''];

                continue;
            }

            $content = [];

            if ($message->content) {
                $content[] = ['type' => 'text', 'text' => $message->content];
            }

            foreach ($message->tool_calls ?? [] as $call) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'input' => (object) $call['input'],
                ];
            }

            if ($content !== []) {
                $out[] = ['role' => 'assistant', 'content' => $content];
            }
        }

        $flushResults();

        // A trailing tool_result must not be the whole conversation.
        return $out;
    }

    private function systemPrompt(Conversation $conversation): string
    {
        $business = Business::current();
        $now = CarbonImmutable::now($business->timezone);

        $services = Service::where('active', true)->orderBy('sort_order')->get()
            ->map(fn (Service $s) => "- {$s->name} (id {$s->id}), {$s->duration_minutes} min, {$s->price}")
            ->implode("\n");

        $hours = WorkingHour::orderBy('day_of_week')->get()
            ->map(fn (WorkingHour $h) => $h->is_closed
                ? '- '.self::DAY_NAMES[$h->day_of_week].': closed'
                : '- '.self::DAY_NAMES[$h->day_of_week].": {$h->open_time} to {$h->close_time}")
            ->implode("\n");

        $known = $conversation->phone()
            ? "You are already speaking with {$conversation->contactName()} ({$conversation->phone()})."
            : 'You do not yet know who you are speaking with.';

        $confirmPolicy = $business->auto_confirm
            ? 'Bookings you make are confirmed immediately — tell the customer they are booked.'
            : 'Bookings you make are requests the business confirms shortly — say so, do not promise a confirmed slot.';

        return <<<PROMPT
        You are the booking assistant for {$business->name}. You help customers book, reschedule and
        cancel appointments over chat. Be warm, brief and concrete — two or three sentences at a time.

        Today is {$now->format('l j F Y')} and the local time is {$now->format('g:i A')} ({$business->timezone}).
        {$known}

        SERVICES
        {$services}

        OPENING HOURS
        {$hours}

        RULES YOU MUST FOLLOW
        1. Never invent or guess an available time. Only offer times returned by check_availability
           in this conversation, and book using the exact starts_at value it gave you.
        2. Never invent prices or durations — call list_services.
        3. Get the customer's name and phone number before booking. Ask for both together, once.
        4. Repeat the service, day and time back and get a clear "yes" before calling create_booking.
        5. {$confirmPolicy}
        6. After booking, give the customer their reference number.
        7. Only discuss this business and its appointments. If asked anything else — complaints,
           refunds, prices you cannot verify, or if they ask for a person — call handoff_to_human.
        8. Never claim to have done something you have not done with a tool.
        PROMPT;
    }

    private function textFrom(array $blocks): string
    {
        return trim(collect($blocks)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n"));
    }

    private function toolCallsFrom(array $blocks): array
    {
        return collect($blocks)
            ->where('type', 'tool_use')
            ->map(fn ($block) => [
                'id' => $block['id'],
                'name' => $block['name'],
                'input' => $block['input'] ?? [],
            ])
            ->values()
            ->all();
    }
}

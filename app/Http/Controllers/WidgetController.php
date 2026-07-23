<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Services\Agent\AgentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AgentService $agent) {}

    /** Everything the widget needs to render before the first message. */
    public function bootstrap(): JsonResponse
    {
        $business = Business::current();

        return $this->sendSuccess([
            'business' => [
                'name' => $business->name,
                'phone' => $business->phone,
                'timezone' => $business->timezone,
            ],
            'services' => Service::where('active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Service $s) => [
                    'name' => $s->name,
                    'duration_minutes' => $s->duration_minutes,
                    'price' => (string) $s->price,
                ]),
            'hours' => WorkingHour::orderBy('day_of_week')->get(['day_of_week', 'open_time', 'close_time', 'is_closed']),
            'greeting' => "Hi! I'm the booking assistant for {$business->name}. What can I book you in for?",
            'quick_starts' => [
                'Book an appointment',
                'What services do you offer?',
                'What are your opening hours?',
            ],
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'conversation_token' => ['nullable', 'string', 'size:40'],
        ]);

        $conversation = $this->resolveConversation($validated['conversation_token'] ?? null);

        $bookingIdsBefore = $conversation->bookings()->pluck('id');
        $lastMessageId = (int) $conversation->messages()->max('id');

        $reply = $this->agent->handle($conversation, $validated['message']);

        // If the agent booked during this turn, hand it back so the widget can
        // render a confirmation card instead of plain text.
        $newBooking = $conversation->bookings()
            ->whereNotIn('id', $bookingIdsBefore)
            ->with('service')
            ->latest('id')
            ->first();

        return $this->sendSuccess([
            'reply' => $reply,
            'conversation_token' => $conversation->token,
            'status' => $conversation->fresh()->status,
            'booking' => $newBooking ? new BookingResource($newBooking) : null,
            'slots' => $this->slotsOfferedIn($conversation, $lastMessageId),
        ]);
    }

    /**
     * Times the agent looked up during this turn, so the widget can offer them
     * as tappable chips instead of making the customer type a time back.
     */
    private function slotsOfferedIn(Conversation $conversation, int $afterMessageId): ?array
    {
        $lookup = $conversation->messages()
            ->where('id', '>', $afterMessageId)
            ->where('tool_name', 'check_availability')
            ->reorder('id', 'desc')
            ->first();

        $result = $lookup?->tool_result;

        if (! $result || ! ($result['available'] ?? false)) {
            return null;
        }

        return [
            'date' => $result['date'] ?? null,
            'morning' => $result['morning'] ?? [],
            'afternoon' => $result['afternoon'] ?? [],
            'evening' => $result['evening'] ?? [],
        ];
    }

    private function resolveConversation(?string $token): Conversation
    {
        if ($token) {
            $existing = Conversation::where('token', $token)->first();

            if ($existing) {
                return $existing;
            }
        }

        return Conversation::start();
    }
}

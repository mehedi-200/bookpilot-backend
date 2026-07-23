<?php

namespace App\Services\Agent;

use App\Models\Conversation;
use App\Services\Agent\Tools\AgentTool;
use App\Services\Agent\Tools\CancelBookingTool;
use App\Services\Agent\Tools\CheckAvailabilityTool;
use App\Services\Agent\Tools\CreateBookingTool;
use App\Services\Agent\Tools\HandoffToHumanTool;
use App\Services\Agent\Tools\ListServicesTool;
use App\Services\Agent\Tools\RescheduleBookingTool;
use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    /** @var array<string, AgentTool> */
    private array $tools;

    public function __construct(
        ListServicesTool $listServices,
        CheckAvailabilityTool $checkAvailability,
        CreateBookingTool $createBooking,
        RescheduleBookingTool $rescheduleBooking,
        CancelBookingTool $cancelBooking,
        HandoffToHumanTool $handoff,
    ) {
        foreach ([
            $listServices, $checkAvailability, $createBooking,
            $rescheduleBooking, $cancelBooking, $handoff,
        ] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** Definitions sent to Claude on every call. */
    public function definitions(): array
    {
        return array_values(array_map(fn (AgentTool $tool) => $tool->definition(), $this->tools));
    }

    public function execute(string $name, array $input, Conversation $conversation): array
    {
        $tool = $this->tools[$name] ?? null;

        if (! $tool) {
            return ['error' => "Unknown tool '{$name}'."];
        }

        try {
            return $tool->execute($input, $conversation);
        } catch (\Throwable $e) {
            // A broken tool must not kill the conversation.
            Log::error('BookPilot tool failed', [
                'tool' => $name,
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);

            return ['error' => 'That step failed unexpectedly. Offer to pass this to a human.'];
        }
    }
}

<?php

namespace App\Services\Agent\Tools;

use App\Models\Conversation;
use App\Models\Service;
use App\Services\AvailabilityService;

class CheckAvailabilityTool implements AgentTool
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function name(): string
    {
        return 'check_availability';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Find the free appointment times for a service on a specific date. '
                .'You MUST call this before offering any time to the customer, and you may only '
                .'offer times this tool returned. Use the exact "starts_at" value when booking.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => [
                        'type' => 'integer',
                        'description' => 'The service to book, from list_services.',
                    ],
                    'date' => [
                        'type' => 'string',
                        'description' => 'The date to check, as YYYY-MM-DD.',
                    ],
                ],
                'required' => ['service_id', 'date'],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $service = Service::where('active', true)->find($input['service_id'] ?? null);

        if (! $service) {
            return ['error' => 'That service does not exist. Call list_services to see the options.'];
        }

        $date = $input['date'] ?? '';

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['error' => 'The date must be in YYYY-MM-DD format.'];
        }

        $result = $this->availability->slots($service, $date);

        if ($result['total'] === 0) {
            return [
                'date' => $date,
                'available' => false,
                'reason' => $result['closed_reason'] ?? 'No free times left on this date.',
            ];
        }

        // Give the model both a human label and the exact value booking needs.
        $flatten = fn (array $slots) => array_map(fn ($slot) => [
            'time' => $slot['label'],
            'starts_at' => $slot['start'],
        ], $slots);

        return [
            'date' => $date,
            'available' => true,
            'service' => $service->name,
            'morning' => $flatten($result['slots']['morning']),
            'afternoon' => $flatten($result['slots']['afternoon']),
            'evening' => $flatten($result['slots']['evening']),
        ];
    }
}

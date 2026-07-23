<?php

namespace App\Services\Agent\Tools;

use App\Models\Conversation;
use App\Models\Service;

class ListServicesTool implements AgentTool
{
    public function name(): string
    {
        return 'list_services';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'List the services this business offers with their duration and price. '
                .'Call this before quoting any price or duration — never guess them.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $services = Service::where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => [
                'service_id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => (string) $service->price,
            ])
            ->all();

        return $services === []
            ? ['services' => [], 'note' => 'This business has no bookable services set up yet.']
            : ['services' => $services];
    }
}

<?php

namespace App\Services\Agent\Tools;

use App\Models\Business;
use App\Models\Conversation;

class HandoffToHumanTool implements AgentTool
{
    public function name(): string
    {
        return 'handoff_to_human';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Hand the conversation to a human when you cannot help — complaints, '
                .'refunds, anything outside booking, or when the customer asks for a person. '
                .'The business is notified and will follow up.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Short summary of what the customer needs.',
                    ],
                ],
                'required' => ['reason'],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $business = Business::current();

        $conversation->update([
            'status' => Conversation::STATUS_HANDED_OFF,
            'handoff_reason' => $input['reason'] ?? null,
        ]);

        return [
            'handed_off' => true,
            'business_phone' => $business->phone,
            'message' => $business->phone
                ? 'Tell the customer someone will follow up, and give them this phone number.'
                : 'Tell the customer someone from the team will follow up shortly.',
        ];
    }
}

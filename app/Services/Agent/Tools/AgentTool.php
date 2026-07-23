<?php

namespace App\Services\Agent\Tools;

use App\Models\Conversation;

interface AgentTool
{
    public function name(): string;

    /** Anthropic tool definition: name, description, input_schema. */
    public function definition(): array;

    /**
     * Run the tool. Never throws for expected problems — returns
     * ['error' => '…'] so the agent can recover and tell the customer.
     */
    public function execute(array $input, Conversation $conversation): array;
}

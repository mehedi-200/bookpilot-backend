<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Anthropic Messages API — kept separate so the agent
 * loop can be tested without a network call.
 */
class ClaudeClient
{
    public function messages(string $system, array $messages, array $tools): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('bookpilot.anthropic_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('bookpilot.model'),
                'max_tokens' => config('bookpilot.max_tokens'),
                'system' => $system,
                'messages' => $messages,
                'tools' => $tools,
            ]);

        $response->throw();

        return $response->json();
    }
}

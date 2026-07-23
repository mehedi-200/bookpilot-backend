<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIntegrationRequest;
use App\Models\Booking;
use App\Models\Integration;
use App\Services\GarageFlow\GarageFlowService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GarageFlowService $garageflow)
    {
    }

    public function show(): JsonResponse
    {
        return $this->sendSuccess($this->present(Integration::garageflow()));
    }

    public function update(UpdateIntegrationRequest $request): JsonResponse
    {
        $integration = Integration::garageflow();
        $data = $request->validated();

        if (blank($data['api_token'] ?? null)) {
            unset($data['api_token']); // keep the stored token
        }

        $integration->fill($data)->save();

        return $this->sendSuccess($this->present($integration->fresh()), 'Integration saved');
    }

    public function test(): JsonResponse
    {
        $result = $this->garageflow->testConnection(Integration::garageflow());

        return $result['ok']
            ? $this->sendSuccess($result, "Connected as {$result['connected_as']}")
            : $this->sendError($result['error'], 422);
    }

    /** Mechanics to choose from — GarageFlow needs one on every job. */
    public function mechanics(): JsonResponse
    {
        try {
            $users = $this->garageflow->client()->users();
        } catch (\Throwable) {
            return $this->sendError('Could not load mechanics — check the connection first.', 422);
        }

        return $this->sendSuccess(array_map(fn ($user) => [
            'id' => $user['id'],
            'name' => $user['name'] ?? 'Unnamed',
            'role' => $user['role'] ?? null,
        ], $users));
    }

    /** Manual retry — runs inline so the UI can show the outcome immediately. */
    public function syncBooking(Booking $booking): JsonResponse
    {
        try {
            $this->garageflow->sync($booking);
        } catch (\Throwable $e) {
            $this->garageflow->markFailed($booking, $e);

            return $this->sendError('Sync failed. '.$e->getMessage(), 422);
        }

        return $this->sendSuccess(
            ['sync_status' => $booking->fresh()->sync_status],
            'Sent to GarageFlow'
        );
    }

    private function present(Integration $integration): array
    {
        return [
            'provider' => $integration->provider,
            'base_url' => $integration->base_url,
            'has_token' => filled($integration->api_token),
            'masked_token' => $integration->maskedToken(),
            'default_mechanic_id' => $integration->default_mechanic_id,
            'default_mechanic_name' => $integration->default_mechanic_name,
            'enabled' => $integration->enabled,
            'is_ready' => $integration->isReady(),
            'last_ok_at' => $integration->last_ok_at?->toISOString(),
            'last_error' => $integration->last_error,
        ];
    }
}

<?php

namespace App\Services\GarageFlow;

use App\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper over the GarageFlow API. Every method maps to one real
 * endpoint (see garageflow-api/routes/api.php) so the payload shapes stay
 * honest — GarageFlow requires a mechanic on every job and a registration
 * number on every vehicle.
 */
class GarageFlowClient
{
    public function __construct(private readonly Integration $integration) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->integration->base_url, '/').'/api')
            ->withToken((string) $this->integration->api_token)
            ->acceptJson()
            ->timeout(10);
    }

    /** Who does this token belong to? Used by "Test connection". */
    public function profile(): array
    {
        return $this->http()->get('/profile')->throw()->json('data') ?? [];
    }

    /** Candidates for the default mechanic dropdown. */
    public function users(): array
    {
        $body = $this->http()->get('/users')->throw()->json();

        return $body['data']['data'] ?? $body['data'] ?? [];
    }

    public function findCustomerByPhone(string $phone): ?array
    {
        $body = $this->http()->get('/customers', ['search' => $phone])->throw()->json();
        $rows = $body['data']['data'] ?? $body['data'] ?? [];

        foreach ($rows as $row) {
            if ($this->digits($row['phone'] ?? '') === $this->digits($phone)) {
                return $row;
            }
        }

        return null;
    }

    public function createCustomer(array $payload): array
    {
        return $this->http()->post('/customers', $payload)->throw()->json('data');
    }

    public function findVehicleByRegistration(string $registration): ?array
    {
        $body = $this->http()->get('/vehicles', ['search' => $registration])->throw()->json();
        $rows = $body['data']['data'] ?? $body['data'] ?? [];

        foreach ($rows as $row) {
            if (($row['registration_no'] ?? null) === $registration) {
                return $row;
            }
        }

        return null;
    }

    public function createVehicle(array $payload): array
    {
        return $this->http()->post('/vehicles', $payload)->throw()->json('data');
    }

    public function createServiceJob(array $payload): array
    {
        return $this->http()->post('/service-jobs', $payload)->throw()->json('data');
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}

<?php

namespace App\Services\GarageFlow;

use App\Models\Booking;
use App\Models\Integration;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class GarageFlowService
{
    /** GarageFlow only accepts these; anything else has to be mapped. */
    private const TYPE_KEYWORDS = [
        'Oil Change' => ['oil'],
        'Brakes' => ['brake'],
        'Engine' => ['engine', 'motor'],
        'Electrical' => ['electric', 'battery', 'wiring'],
        'AC' => ['ac', 'air con', 'aircon', 'cooling'],
        'Bodywork' => ['body', 'paint', 'dent', 'scratch'],
    ];

    public function integration(): Integration
    {
        return Integration::garageflow();
    }

    public function client(?Integration $integration = null): GarageFlowClient
    {
        return new GarageFlowClient($integration ?? $this->integration());
    }

    /**
     * Check the credentials and remember the outcome, so the settings page can
     * show a real status instead of asking the owner to guess.
     */
    public function testConnection(Integration $integration): array
    {
        try {
            $profile = $this->client($integration)->profile();

            $integration->forceFill([
                'last_ok_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'ok' => true,
                'connected_as' => $profile['email'] ?? $profile['name'] ?? 'unknown user',
            ];
        } catch (\Throwable $e) {
            $message = $this->humanError($e);

            $integration->forceFill(['last_error' => $message])->save();

            return ['ok' => false, 'error' => $message];
        }
    }

    /**
     * Push a confirmed booking into GarageFlow as a service job.
     * Throws on failure so the queued job can retry.
     */
    public function sync(Booking $booking): void
    {
        $integration = $this->integration();

        if (! $integration->isReady()) {
            throw new \RuntimeException('The GarageFlow integration is not configured.');
        }

        $client = $this->client($integration);
        $booking->loadMissing(['customer', 'service']);

        $customer = $this->resolveCustomer($client, $booking);
        $vehicle = $this->resolveVehicle($client, $booking, $customer);

        $job = $client->createServiceJob([
            'vehicle_id' => $vehicle['id'],
            'mechanic_id' => $integration->default_mechanic_id,
            'service_type' => $this->serviceTypeFor($booking->service?->name ?? ''),
            'description' => $this->descriptionFor($booking),
            'expected_delivery' => $this->expectedDeliveryFor($booking),
        ]);

        $booking->forceFill([
            'garageflow_job_id' => (string) ($job['id'] ?? ''),
            'sync_status' => 'synced',
            'synced_at' => now(),
        ])->save();

        $integration->forceFill(['last_ok_at' => now(), 'last_error' => null])->save();
    }

    public function markFailed(Booking $booking, \Throwable $e): void
    {
        $message = $this->humanError($e);

        $booking->forceFill([
            'sync_status' => 'failed',
            'sync_attempts' => $booking->sync_attempts + 1,
        ])->save();

        Integration::garageflow()->forceFill(['last_error' => $message])->save();

        Log::channel('stack')->error('GarageFlow sync failed', [
            'booking' => $booking->reference,
            'error' => $message,
        ]);
    }

    private function resolveCustomer(GarageFlowClient $client, Booking $booking): array
    {
        $phone = $booking->customer->phone;

        return $client->findCustomerByPhone($phone) ?? $client->createCustomer([
            'name' => $booking->customer->name,
            'phone' => $phone,
            'email' => $booking->customer->email,
        ]);
    }

    /**
     * BookPilot doesn't collect vehicles, but GarageFlow requires one with a
     * unique registration. A deterministic placeholder per customer means
     * repeat bookings reuse the same vehicle instead of piling up duplicates.
     */
    private function resolveVehicle(GarageFlowClient $client, Booking $booking, array $customer): array
    {
        $registration = 'BP-'.PhoneNumber::normalize($booking->customer->phone);

        return $client->findVehicleByRegistration($registration) ?? $client->createVehicle([
            'customer_id' => $customer['id'],
            'registration_no' => $registration,
            'brand' => 'Unknown',
            'model' => 'Unknown',
            'year' => (int) now()->format('Y'),
            'notes' => 'Created automatically from a BookPilot booking. '
                .'Update with the real vehicle details when the customer arrives.',
        ]);
    }

    private function serviceTypeFor(string $serviceName): string
    {
        $haystack = strtolower($serviceName);

        foreach (self::TYPE_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $type;
                }
            }
        }

        return 'General Service';
    }

    private function descriptionFor(Booking $booking): string
    {
        $lines = [
            "Booked via BookPilot ({$booking->reference}).",
            'Service: '.($booking->service?->name ?? 'unknown'),
            'Appointment: '.$booking->starts_at->format('D j M Y, g:i A'),
        ];

        if ($booking->notes) {
            $lines[] = 'Customer notes: '.$booking->notes;
        }

        return implode("\n", $lines);
    }

    /** GarageFlow rejects a delivery date in the past. */
    private function expectedDeliveryFor(Booking $booking): ?string
    {
        return $booking->starts_at->isFuture()
            ? $booking->starts_at->toDateString()
            : null;
    }

    /** Turn transport noise into something an owner can act on. */
    private function humanError(\Throwable $e): string
    {
        // RequestException exposes $response as a property, not a method.
        $response = $e instanceof RequestException ? $e->response : null;
        $status = $response?->status();

        return match ($status) {
            401, 403 => 'GarageFlow rejected the API token. Generate a new one and paste it here.',
            404 => 'That URL does not look like a GarageFlow API. Check the address.',
            422 => 'GarageFlow rejected the data: '.($response?->json('message') ?? 'validation failed'),
            null => str_contains($e->getMessage(), 'cURL')
                || str_contains($e->getMessage(), 'Could not resolve')
                || $e instanceof ConnectionException
                ? 'Could not reach that address. Check the URL and that GarageFlow is running.'
                : mb_substr($e->getMessage(), 0, 200),
            default => "GarageFlow returned an error ({$status}).",
        };
    }
}

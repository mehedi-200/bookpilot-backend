<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 weeks', '+2 weeks');

        return [
            'reference' => 'BP-'.date('Y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory(),
            'service_id' => Service::factory(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+60 minutes'),
            'status' => Booking::STATUS_PENDING,
            'source' => Booking::SOURCE_MANUAL,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function fromWidget(): static
    {
        return $this->state(fn () => ['source' => Booking::SOURCE_WIDGET]);
    }
}

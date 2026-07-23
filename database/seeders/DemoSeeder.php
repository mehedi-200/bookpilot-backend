<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A believable demo environment — not lorem ipsum. Run with:
 *   php artisan db:seed --class=DemoSeeder
 *
 * Deliberately kept out of DatabaseSeeder so demo customers can never appear
 * in a real installation.
 */
class DemoSeeder extends Seeder
{
    private const SERVICES = [
        ['Full Service', 120, 4500],
        ['Oil Change', 30, 1200],
        ['Brake Check', 45, 1800],
        ['AC Servicing', 60, 2500],
        ['Engine Diagnostics', 45, 2000],
        ['Wheel Alignment', 45, 1500],
        ['Battery Replacement', 30, 3500],
        ['Pre-purchase Inspection', 90, 3000],
    ];

    private const CUSTOMERS = [
        ['Rahim Uddin', '01712345678'],
        ['Karim Ahmed', '01811223344'],
        ['Fatema Begum', '01919876543'],
        ['Jasim Mia', '01677889900'],
        ['Nusrat Jahan', '01521114455'],
        ['Shakib Hasan', '01787654321'],
        ['Ayesha Siddika', '01698765432'],
        ['Tanvir Alam', '01911223300'],
        ['Sumaiya Akter', '01755667788'],
        ['Imran Hossain', '01844556677'],
        ['Rubina Khatun', '01933445566'],
        ['Sabbir Rahman', '01622334455'],
        ['Mizanur Rahman', '01766554433'],
        ['Farhana Yasmin', '01877665544'],
        ['Arif Chowdhury', '01988776655'],
    ];

    public function run(): void
    {
        $this->command?->info('Seeding demo data…');

        $business = $this->business();
        $staff = $this->team();
        $services = $this->services();
        $customers = $this->customers();
        $bookings = $this->bookings($services, $customers, $business);
        $this->conversations($customers, $services, $bookings);
        $this->notifications($staff, $bookings);

        $this->command?->info(sprintf(
            'Done: %d services, %d customers, %d bookings, %d conversations.',
            Service::count(),
            Customer::count(),
            Booking::count(),
            Conversation::count(),
        ));
    }

    private function business(): Business
    {
        $business = Business::current();

        $business->update([
            'name' => "Rahim's Garage",
            'slug' => 'rahims-garage',
            'phone' => '01700000000',
            'email' => 'hello@rahimsgarage.test',
            'address' => '42 Mirpur Road, Dhaka',
            'timezone' => 'Asia/Dhaka',
            'auto_confirm' => false,
        ]);

        // Open Sat–Thu, closed Friday (the local weekend).
        foreach (range(0, 6) as $day) {
            $closed = $day === 5;
            WorkingHour::where('day_of_week', $day)->update([
                'is_closed' => $closed,
                'open_time' => $closed ? null : '09:00',
                'close_time' => $closed ? null : '18:00',
            ]);
        }

        return $business->fresh();
    }

    /** @return array<User> */
    private function team(): array
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@bookpilot.test'],
            ['name' => 'Rahim Uddin', 'password' => 'password', 'role' => User::ROLE_ADMIN]
        );

        $staff = collect([
            ['Nadia Islam', 'nadia@bookpilot.test'],
            ['Sohel Rana', 'sohel@bookpilot.test'],
        ])->map(fn ($row) => User::firstOrCreate(
            ['email' => $row[1]],
            ['name' => $row[0], 'password' => 'password', 'role' => User::ROLE_STAFF]
        ))->all();

        return [$admin, ...$staff];
    }

    /** @return array<Service> */
    private function services(): array
    {
        $services = [];

        foreach (self::SERVICES as $index => [$name, $duration, $price]) {
            $services[] = Service::firstOrCreate(
                ['name' => $name],
                [
                    'duration_minutes' => $duration,
                    'price' => $price,
                    'active' => $index < 7, // one retired service, so the filter has something to show
                    'sort_order' => $index,
                ]
            );
        }

        return $services;
    }

    /** @return array<Customer> */
    private function customers(): array
    {
        return collect(self::CUSTOMERS)
            ->map(fn ($row) => Customer::firstOrCreate(
                ['phone' => $row[1]],
                ['name' => $row[0], 'email' => null]
            ))
            ->all();
    }

    /**
     * 40 bookings across ±3 weeks. Times are laid out one per hour per day so
     * they never overlap — the engine would reject them otherwise.
     *
     * @return array<Booking>
     */
    private function bookings(array $services, array $customers, Business $business): array
    {
        $tz = $business->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $active = array_slice($services, 0, 7);
        $sequence = Booking::count();
        $bookings = [];

        // dayOffset => [hours]
        $schedule = [
            -18 => [9, 11, 14], -15 => [10, 13], -12 => [9, 12, 15], -9 => [10, 14],
            -6 => [9, 11, 16], -4 => [10, 13], -2 => [9, 14], -1 => [10, 11, 15],
            0 => [9, 11, 14, 16], 1 => [10, 12, 15], 2 => [9, 13],
            3 => [10, 14, 16], 5 => [9, 11], 7 => [10, 13, 15], 9 => [9, 14],
            12 => [11], 14 => [10, 15],
        ];

        foreach ($schedule as $dayOffset => $hours) {
            $day = $today->addDays($dayOffset);

            if ($day->dayOfWeek === 5) {
                continue; // Friday: closed
            }

            foreach ($hours as $index => $hour) {
                $service = $active[$this->wrap($dayOffset + $index, count($active))];
                $customer = $customers[$this->wrap($dayOffset * 3 + $index, count($customers))];
                $start = $day->setTime($hour, 0);

                $bookings[] = Booking::create([
                    'reference' => 'BP-'.$start->year.'-'.str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT),
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'starts_at' => $start->utc(),
                    'ends_at' => $start->addMinutes($service->duration_minutes)->utc(),
                    'status' => $this->statusFor($dayOffset, $index),
                    'source' => $this->wrap($dayOffset + $index, 5) < 3 ? Booking::SOURCE_WIDGET : Booking::SOURCE_MANUAL,
                    'notes' => $index === 0 ? 'Customer mentioned a noise from the front left wheel.' : null,
                ]);
            }
        }

        return $bookings;
    }

    /** PHP's % keeps the dividend's sign; day offsets go negative. */
    private function wrap(int $value, int $modulus): int
    {
        return (($value % $modulus) + $modulus) % $modulus;
    }

    /** Past work is finished (or was cancelled); future work is still pending. */
    private function statusFor(int $dayOffset, int $index): string
    {
        if ($dayOffset < -1) {
            return $this->wrap($dayOffset + $index, 7) === 0
                ? Booking::STATUS_CANCELLED
                : Booking::STATUS_COMPLETED;
        }

        if ($dayOffset <= 0) {
            return Booking::STATUS_CONFIRMED;
        }

        return $this->wrap($dayOffset + $index, 3) === 0
            ? Booking::STATUS_PENDING
            : Booking::STATUS_CONFIRMED;
    }

    private function conversations(array $customers, array $services, array $bookings): void
    {
        $service = $services[0];
        $widgetBookings = array_values(array_filter(
            $bookings,
            fn (Booking $b) => $b->source === Booking::SOURCE_WIDGET
        ));

        // Eight ordinary chats that ended in a booking.
        foreach (array_slice($widgetBookings, 0, 8) as $index => $booking) {
            $customer = $customers[$index % count($customers)];
            $conversation = $this->conversation($customer, Conversation::STATUS_ENDED, $booking->created_at);
            $booking->update(['conversation_id' => $conversation->id]);

            $this->transcript($conversation, $booking, $service);
        }

        // One chat still going.
        $active = $this->conversation($customers[9], Conversation::STATUS_ACTIVE, now()->subMinutes(4));
        $this->say($active, Message::ROLE_USER, 'do you do AC servicing?');
        $this->tool($active, 'list_services', [], [
            'services' => [['service_id' => $services[3]->id, 'name' => 'AC Servicing', 'duration_minutes' => 60, 'price' => '2500.00']],
        ]);
        $this->say($active, Message::ROLE_ASSISTANT, 'Yes — AC Servicing takes about an hour and costs 2500. Would you like me to find you a time?');

        // One that needed a human.
        $handoff = $this->conversation($customers[10], Conversation::STATUS_HANDED_OFF, now()->subHours(3));
        $handoff->update(['handoff_reason' => 'Wants a refund for a previous repair']);
        $this->say($handoff, Message::ROLE_USER, 'I paid for a repair last week and the problem is back. I want my money back.');
        $this->tool($handoff, 'handoff_to_human', ['reason' => 'Wants a refund for a previous repair'], [
            'handed_off' => true,
            'business_phone' => '01700000000',
        ]);
        $this->say($handoff, Message::ROLE_ASSISTANT, 'I’m sorry about that — that needs one of the team. Please call us on 01700000000 and we’ll sort it out.');
    }

    private function conversation(Customer $customer, string $status, $activityAt): Conversation
    {
        $conversation = Conversation::start();

        $conversation->update([
            'customer_id' => $customer->id,
            'guest_name' => $customer->name,
            'guest_phone' => $customer->phone,
            'status' => $status,
            'last_activity_at' => $activityAt,
        ]);

        return $conversation;
    }

    /** A realistic booking flow: ask → look up → offer → confirm → book. */
    private function transcript(Conversation $conversation, Booking $booking, Service $service): void
    {
        $when = $booking->starts_at->setTimezone('Asia/Dhaka');
        $date = $when->format('Y-m-d');

        $this->say($conversation, Message::ROLE_USER, 'hi, can I book a '.strtolower($booking->service->name).'?');
        $this->tool($conversation, 'list_services', [], [
            'services' => [[
                'service_id' => $booking->service_id,
                'name' => $booking->service->name,
                'duration_minutes' => $booking->service->duration_minutes,
                'price' => (string) $booking->service->price,
            ]],
        ]);
        $this->say($conversation, Message::ROLE_ASSISTANT, "Of course — {$booking->service->name} takes about {$booking->service->duration_minutes} minutes. What day suits you?");
        $this->say($conversation, Message::ROLE_USER, $when->format('l').' if you have anything');
        $this->tool($conversation, 'check_availability', [
            'service_id' => $booking->service_id,
            'date' => $date,
        ], [
            'date' => $date,
            'available' => true,
            'service' => $booking->service->name,
            'morning' => [['time' => '9:00 AM', 'starts_at' => $when->copy()->setTime(9, 0)->utc()->toISOString()]],
            'afternoon' => [['time' => $when->format('g:i A'), 'starts_at' => $when->utc()->toISOString()]],
            'evening' => [],
        ]);
        $this->say($conversation, Message::ROLE_ASSISTANT, 'I can do 9:00 AM or '.$when->format('g:i A').' that day — which works better?');
        $this->say($conversation, Message::ROLE_USER, $when->format('g:i A').' please');
        $this->say($conversation, Message::ROLE_ASSISTANT, 'Great. Can I take your name and phone number to hold it?');
        $this->say($conversation, Message::ROLE_USER, $conversation->guest_name.', '.$conversation->guest_phone);
        $this->say($conversation, Message::ROLE_ASSISTANT, "So that's {$booking->service->name} on ".$when->format('l j F').' at '.$when->format('g:i A').' — shall I book it?');
        $this->say($conversation, Message::ROLE_USER, 'yes please');
        $this->tool($conversation, 'create_booking', [
            'service_id' => $booking->service_id,
            'starts_at' => $booking->starts_at->toISOString(),
            'customer_name' => $conversation->guest_name,
            'customer_phone' => $conversation->guest_phone,
        ], [
            'booked' => true,
            'reference' => $booking->reference,
            'status' => $booking->status,
            'when' => $when->format('l j F, g:i A'),
            'service' => $booking->service->name,
        ]);
        $this->say(
            $conversation,
            Message::ROLE_ASSISTANT,
            "All set — your reference is {$booking->reference}. See you on ".$when->format('l').'!'
        );
    }

    private function say(Conversation $conversation, string $role, string $content): void
    {
        $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
            'input_tokens' => $role === Message::ROLE_ASSISTANT ? random_int(700, 1400) : null,
            'output_tokens' => $role === Message::ROLE_ASSISTANT ? random_int(20, 90) : null,
        ]);
    }

    private function tool(Conversation $conversation, string $name, array $input, array $result): void
    {
        $id = 'toolu_'.str()->random(12);

        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'tool_calls' => [['id' => $id, 'name' => $name, 'input' => $input]],
            'input_tokens' => random_int(700, 1400),
            'output_tokens' => random_int(30, 120),
        ]);

        $conversation->messages()->create([
            'role' => Message::ROLE_TOOL,
            'tool_use_id' => $id,
            'tool_name' => $name,
            'tool_result' => $result,
        ]);
    }

    private function notifications(array $team, array $bookings): void
    {
        $admin = $team[0];
        $recent = array_slice(array_reverse($bookings), 0, 3);

        foreach ($recent as $index => $booking) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => Notification::TYPE_AI_BOOKING,
                'title' => 'New booking from your AI',
                'body' => sprintf(
                    '%s booked %s for %s',
                    $booking->customer->name,
                    $booking->service->name,
                    $booking->starts_at->setTimezone('Asia/Dhaka')->format('D j M, g:i A'),
                ),
                'data' => ['booking_id' => $booking->id],
                'read_at' => $index > 0 ? now() : null,
            ]);
        }

        $handoff = Conversation::where('status', Conversation::STATUS_HANDED_OFF)->first();

        if ($handoff) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => Notification::TYPE_HANDOFF,
                'title' => 'A chat needs a human',
                'body' => $handoff->handoff_reason,
                'data' => ['conversation_id' => $handoff->id],
            ]);
        }
    }
}

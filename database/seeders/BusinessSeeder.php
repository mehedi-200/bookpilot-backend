<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\WorkingHour;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        Business::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'My Business',
                'slug' => 'my-business',
                'timezone' => 'UTC',
                'widget_key' => Business::generateWidgetKey(),
            ]
        );

        // Exactly 7 rows, Mon–Fri 09–18 open, Sat/Sun closed by default.
        foreach (range(0, 6) as $day) {
            $weekend = in_array($day, [0, 6], true);
            WorkingHour::firstOrCreate(
                ['day_of_week' => $day],
                [
                    'is_closed' => $weekend,
                    'open_time' => $weekend ? null : '09:00',
                    'close_time' => $weekend ? null : '18:00',
                ]
            );
        }
    }
}

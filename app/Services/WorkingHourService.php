<?php

namespace App\Services;

use App\Models\ClosedDate;
use App\Models\WorkingHour;
use Illuminate\Support\Collection;

class WorkingHourService
{
    public function all(): Collection
    {
        return WorkingHour::orderBy('day_of_week')->get();
    }

    /**
     * Full 7-day replace — the request has already validated shape and times.
     */
    public function updateAll(array $days): Collection
    {
        foreach ($days as $day) {
            WorkingHour::where('day_of_week', $day['day_of_week'])->update([
                'is_closed' => $day['is_closed'],
                'open_time' => $day['is_closed'] ? null : $day['open_time'],
                'close_time' => $day['is_closed'] ? null : $day['close_time'],
            ]);
        }

        return $this->all();
    }

    public function addClosedDate(array $data): ClosedDate
    {
        return ClosedDate::create($data);
    }

    public function removeClosedDate(ClosedDate $closedDate): void
    {
        $closedDate->delete();
    }
}

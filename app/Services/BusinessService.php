<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Service;
use App\Models\WorkingHour;
use Illuminate\Support\Str;

class BusinessService
{
    public function update(array $data): Business
    {
        $business = Business::current();

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $business->update($data);

        return $business;
    }

    /**
     * Honest onboarding signals — drives the dashboard checklist card.
     */
    public function setupState(Business $business): array
    {
        return [
            'business_configured' => $business->timezone !== 'UTC' || $business->phone !== null,
            'hours_set' => WorkingHour::where('is_closed', false)
                ->whereNotNull('open_time')
                ->exists(),
            'has_services' => Service::where('active', true)->exists(),
            'widget_embedded' => $business->widget_seen_at !== null,
        ];
    }
}

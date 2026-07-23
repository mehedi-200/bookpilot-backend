<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One query set, one response — the dashboard never fans out into a dozen
 * requests. Everything is bucketed in the business timezone.
 */
class DashboardService
{
    public function __construct(private readonly BusinessService $business) {}

    public function summary(): array
    {
        $business = Business::current();
        $tz = $business->timezone;
        $now = CarbonImmutable::now($tz);

        $todayStart = $now->startOfDay();
        $todayEnd = $now->endOfDay();
        $weekEnd = $now->startOfDay()->addDays(7);
        $monthAgo = $now->subDays(30);

        return [
            'stats' => [
                'today' => $this->countBetween($todayStart, $todayEnd),
                'week' => $this->countBetween($todayStart, $weekEnd),
                'pending' => Booking::where('status', Booking::STATUS_PENDING)->count(),
                'customers' => Customer::count(),
                'active_conversations' => Conversation::where('status', Conversation::STATUS_ACTIVE)
                    ->where('last_activity_at', '>=', $now->subDay())
                    ->count(),
            ],
            'needs_attention' => [
                'pending' => Booking::where('status', Booking::STATUS_PENDING)
                    ->where('starts_at', '>=', $now)
                    ->count(),
                'failed_syncs' => Booking::where('sync_status', 'failed')->count(),
                'handoffs' => Conversation::where('status', Conversation::STATUS_HANDED_OFF)->count(),
            ],
            'today' => Booking::with(['customer', 'service'])
                ->whereBetween('starts_at', [$todayStart, $todayEnd])
                ->whereNot('status', Booking::STATUS_CANCELLED)
                ->orderBy('starts_at')
                ->get(),
            'upcoming' => Booking::with(['customer', 'service'])
                ->where('starts_at', '>', $todayEnd)
                ->whereIn('status', Booking::BLOCKING_STATUSES)
                ->orderBy('starts_at')
                ->limit(5)
                ->get(),
            'by_status' => $this->countsByStatus(),
            'ai_share' => $this->aiShare($monthAgo),
            'setup_state' => $this->business->setupState($business),
        ];
    }

    private function countBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Booking::whereBetween('starts_at', [$from, $to])
            ->whereNot('status', Booking::STATUS_CANCELLED)
            ->count();
    }

    private function countsByStatus(): array
    {
        $counts = Booking::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            Booking::STATUS_PENDING => (int) ($counts[Booking::STATUS_PENDING] ?? 0),
            Booking::STATUS_CONFIRMED => (int) ($counts[Booking::STATUS_CONFIRMED] ?? 0),
            Booking::STATUS_COMPLETED => (int) ($counts[Booking::STATUS_COMPLETED] ?? 0),
            Booking::STATUS_CANCELLED => (int) ($counts[Booking::STATUS_CANCELLED] ?? 0),
        ];
    }

    /** How much of the last 30 days' work the agent handled on its own. */
    private function aiShare(CarbonImmutable $since): array
    {
        $counts = Booking::where('created_at', '>=', $since)
            ->select('source', DB::raw('count(*) as total'))
            ->groupBy('source')
            ->pluck('total', 'source');

        $ai = (int) ($counts[Booking::SOURCE_WIDGET] ?? 0);
        $manual = (int) ($counts[Booking::SOURCE_MANUAL] ?? 0);
        $total = $ai + $manual;

        return [
            'ai' => $ai,
            'manual' => $manual,
            'percent' => $total > 0 ? (int) round($ai / $total * 100) : 0,
        ];
    }
}

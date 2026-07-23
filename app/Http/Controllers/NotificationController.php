<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notifications->paginate($request->user(), $request->only('per_page'));

        return $this->sendSuccess($notifications->toArray());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->sendSuccess(['count' => $this->notifications->unreadCount($request->user())]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Yours only — notifications are per user.
        abort_if($notification->user_id !== $request->user()->id, 404);

        return $this->sendSuccess($this->notifications->markRead($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return $this->sendSuccess(['marked' => $count], 'All caught up');
    }
}

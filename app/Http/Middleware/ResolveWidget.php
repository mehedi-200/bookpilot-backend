<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the public widget by its key. The key travels in a header so
 * it never lands in server logs as a query string.
 */
class ResolveWidget
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Widget-Key') ?? $request->query('widget_key');

        $business = $key ? Business::where('widget_key', $key)->first() : null;

        if (! $business) {
            return $this->sendError('Invalid widget key.', 401);
        }

        // Honest "is it live on their site?" signal for the setup checklist.
        if (! $business->widget_seen_at) {
            $business->forceFill(['widget_seen_at' => now()])->save();
        }

        $request->attributes->set('business', $business);

        return $next($request);
    }
}

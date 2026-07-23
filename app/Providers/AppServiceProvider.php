<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Widget chat is public and costs money per message: limit per
        // conversation where we have one, otherwise per IP.
        RateLimiter::for('widget-chat', function (Request $request) {
            $token = $request->input('conversation_token');

            return [
                Limit::perMinute(10)->by($token ?: $request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
    }
}

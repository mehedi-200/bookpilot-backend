<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Booking rules
    |--------------------------------------------------------------------------
    | Minimum notice before a slot can be booked. A slot starting sooner than
    | this many minutes from now is never offered — to the dashboard, the
    | widget, or the AI agent.
    */
    'lead_time_minutes' => (int) env('BOOKPILOT_LEAD_TIME_MINUTES', 60),

    /*
    | How many days ahead the booking UI and the agent may look.
    */
    'booking_window_days' => (int) env('BOOKPILOT_BOOKING_WINDOW_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | AI agent (used from Feature 6)
    |--------------------------------------------------------------------------
    */
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('BOOKPILOT_MODEL', 'claude-sonnet-5'),
    'max_tokens' => (int) env('BOOKPILOT_MAX_TOKENS', 1024),
    'max_iterations' => (int) env('BOOKPILOT_MAX_ITERATIONS', 8),
];

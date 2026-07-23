<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WidgetController;
use App\Http\Controllers\WorkingHourController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', [HealthController::class, 'ping']);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// ── Public widget (authenticated by widget key, not a user) ──────────────
Route::middleware('widget')->prefix('widget')->group(function () {
    Route::get('/bootstrap', [WidgetController::class, 'bootstrap']);
    Route::post('/chat', [WidgetController::class, 'chat'])->middleware('throttle:widget-chat');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/business', [BusinessController::class, 'show']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/working-hours', [WorkingHourController::class, 'index']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/search', [SearchController::class, 'index']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/availability', [AvailabilityController::class, 'index']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
    Route::patch('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('/bookings/{booking}/sync', [IntegrationController::class, 'syncBooking']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

    Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);

        Route::put('/business', [BusinessController::class, 'update']);
        Route::post('/business/widget-key/regenerate', [BusinessController::class, 'regenerateWidgetKey']);

        Route::post('/services', [ServiceController::class, 'store']);
        Route::put('/services/{service}', [ServiceController::class, 'update']);
        Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
        Route::patch('/services/{service}/toggle-active', [ServiceController::class, 'toggleActive']);

        Route::put('/working-hours', [WorkingHourController::class, 'update']);
        Route::post('/closed-dates', [WorkingHourController::class, 'storeClosedDate']);
        Route::delete('/closed-dates/{closedDate}', [WorkingHourController::class, 'destroyClosedDate']);

        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

        Route::get('/integrations/garageflow', [IntegrationController::class, 'show']);
        Route::put('/integrations/garageflow', [IntegrationController::class, 'update']);
        Route::post('/integrations/garageflow/test', [IntegrationController::class, 'test']);
        Route::get('/integrations/garageflow/mechanics', [IntegrationController::class, 'mechanics']);
    });
});

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkingHourController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', [HealthController::class, 'ping']);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/business', [BusinessController::class, 'show']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/working-hours', [WorkingHourController::class, 'index']);

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
    });
});

<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::apiResource('customers', CustomerController::class)->except('destroy');
        Route::get('customers/{customer}/devices', [DeviceController::class, 'index']);
        Route::post('customers/{customer}/devices', [DeviceController::class, 'store']);
        Route::patch('devices/{device}', [DeviceController::class, 'update']);

        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show']);
        Route::get('work-orders/{workOrder}/events', [WorkOrderController::class, 'events']);
        Route::post('work-orders/{workOrder}/start-diagnosis', [WorkOrderController::class, 'startDiagnosis']);
        Route::patch('work-orders/{workOrder}/diagnosis', [WorkOrderController::class, 'recordDiagnosis']);

        Route::post('work-orders/{workOrder}/quotes', [QuoteController::class, 'store']);
        Route::put('quotes/{quote}/draft', [QuoteController::class, 'updateDraft']);
        Route::post('quotes/{quote}/issue', [QuoteController::class, 'issue']);
        Route::post('quotes/{quote}/decision', [QuoteController::class, 'decide']);

        Route::get('technicians', TechnicianController::class);
    });
});

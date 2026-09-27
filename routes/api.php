<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\PartController;
use App\Http\Controllers\Api\V1\PdfController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\QuoteDeliveryController;
use App\Http\Controllers\Api\V1\SupplierController;
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

        Route::get('work-orders/{workOrder}/attachments', [AttachmentController::class, 'index']);
        Route::post('work-orders/{workOrder}/attachments', [AttachmentController::class, 'store']);
        Route::get('work-orders/{workOrder}/attachments/{attachment}', [AttachmentController::class, 'download'])->scopeBindings();

        Route::get('work-orders/{workOrder}/quotes', [QuoteController::class, 'index']);
        Route::post('work-orders/{workOrder}/quotes', [QuoteController::class, 'store']);
        Route::post('work-orders/{workOrder}/consumptions', [WorkOrderController::class, 'consumeParts']);
        Route::post('work-orders/{workOrder}/start-repair', [WorkOrderController::class, 'startRepair']);
        Route::post('work-orders/{workOrder}/complete-repair', [WorkOrderController::class, 'completeRepair']);
        Route::post('work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel']);
        Route::post('work-orders/{workOrder}/returns', [WorkOrderController::class, 'openReturn']);
        Route::post('work-orders/{workOrder}/deliver', [WorkOrderController::class, 'deliver']);
        Route::post('work-orders/{workOrder}/reservations', [WorkOrderController::class, 'reserveParts']);
        Route::post('work-orders/{workOrder}/addenda', [QuoteController::class, 'storeAddendum']);
        Route::post('work-orders/{workOrder}/quote-revisions', [QuoteController::class, 'revise']);
        Route::put('quotes/{quote}/draft', [QuoteController::class, 'updateDraft']);
        Route::post('quotes/{quote}/issue', [QuoteController::class, 'issue']);
        Route::post('quotes/{quote}/decision', [QuoteController::class, 'decide']);
        Route::post('quotes/{quote}/deliveries', [QuoteDeliveryController::class, 'store']);

        Route::get('parts', [PartController::class, 'index']);
        Route::post('parts', [PartController::class, 'store']);
        Route::get('parts/{part}', [PartController::class, 'show']);
        Route::patch('parts/{part}', [PartController::class, 'update']);
        Route::post('parts/{part}/receipts', [PartController::class, 'receive']);
        Route::get('parts/{part}/movements', [PartController::class, 'movements']);
        Route::get('quotes/{quote}/pdf', [PdfController::class, 'quote']);
        Route::get('work-orders/{workOrder}/pdf', [PdfController::class, 'workOrder']);

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::post('purchase-orders/{purchaseOrder}/receipts', [PurchaseOrderController::class, 'receive']);
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);

        Route::apiResource('suppliers', SupplierController::class)->except('destroy');

        Route::get('technicians', TechnicianController::class);
    });
});

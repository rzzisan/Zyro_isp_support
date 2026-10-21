<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Middleware\ApiCompany;
use Illuminate\Support\Facades\Route;

/*
 * Android app (Zyro Desk), token auth (Sanctum, one token per phone). Everything under /c/{company} needs a
 * membership in that company and checks the same menu permissions as the panel (App\Support\Menu).
 */
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('password', [AuthController::class, 'password'])->middleware('throttle:5,1');
        Route::post('push-token', [AuthController::class, 'pushToken'])->middleware('throttle:20,1');

        Route::prefix('c/{company:slug}')->middleware(ApiCompany::class)->group(function () {
            Route::get('dashboard', DashboardController::class);
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('zones', [CustomerController::class, 'zones']);
            Route::get('customer-filters', [CustomerController::class, 'filters']);
            Route::get('customers/{headerId}', [CustomerController::class, 'show'])->whereNumber('headerId');
            Route::get('customers/{headerId}/live', [CustomerController::class, 'live'])->whereNumber('headerId');
            Route::post('customers/{headerId}/refresh', [CustomerController::class, 'refresh'])->whereNumber('headerId')->middleware('throttle:30,1');
            Route::get('customers/{headerId}/refresh', [CustomerController::class, 'historyStatus'])->whereNumber('headerId');
            Route::post('customers/{headerId}/line', [CustomerController::class, 'line'])->whereNumber('headerId')->middleware('throttle:20,1');
            Route::get('customers/{headerId}/bill-form', [BillController::class, 'form'])->whereNumber('headerId');
            Route::post('customers/{headerId}/bills', [BillController::class, 'store'])->whereNumber('headerId')->middleware('throttle:30,1');
            Route::get('daily-bills', [BillController::class, 'dailyBills']);
            Route::get('collections', [BillController::class, 'collections']);
            Route::get('receipts/collection/{collection}', [BillController::class, 'collectionReceipt'])->whereNumber('collection');
            Route::get('receipts/bill/{dailyBill}', [BillController::class, 'dailyBillReceipt'])->whereNumber('dailyBill');

            Route::get('chats', [InboxController::class, 'index']);
            Route::get('chats/{contact}', [InboxController::class, 'show'])->whereNumber('contact');
            Route::post('chats/{contact}/reply', [InboxController::class, 'reply'])->whereNumber('contact')->middleware('throttle:30,1');
            Route::post('chats/{contact}/assign', [InboxController::class, 'assign'])->whereNumber('contact');
            Route::post('chats/{contact}/bot', [InboxController::class, 'bot'])->whereNumber('contact');
            Route::post('chats/{contact}/mute', [InboxController::class, 'mute'])->whereNumber('contact');
            Route::get('media/{message}', [InboxController::class, 'media'])->whereNumber('message');

            Route::get('monitoring', [MonitoringController::class, 'index']);
            Route::post('monitoring/sync', [MonitoringController::class, 'sync'])->middleware('throttle:10,1');
            Route::post('monitoring/{headerId}/{what}', [MonitoringController::class, 'check'])->whereNumber('headerId')->middleware('throttle:60,1');

            Route::get('calls', [CallController::class, 'index']);
            Route::get('calls/{call}', [CallController::class, 'show'])->whereNumber('call');
            Route::get('calls/{call}/recording', [CallController::class, 'recording'])->whereNumber('call');

            Route::get('tickets', [TicketController::class, 'index']);
            Route::get('ticket-customers', [TicketController::class, 'customers']);
            Route::get('ticket-options', [TicketController::class, 'options']);
            Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:20,1');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
            Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->whereNumber('ticket');
            Route::post('tickets/{ticket}/solve', [TicketController::class, 'solve'])->whereNumber('ticket')->middleware('throttle:30,1');
        });
    });
});

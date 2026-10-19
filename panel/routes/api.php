<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
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

        Route::prefix('c/{company:slug}')->middleware(ApiCompany::class)->group(function () {
            Route::get('dashboard', DashboardController::class);
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('zones', [CustomerController::class, 'zones']);
            Route::get('customers/{headerId}', [CustomerController::class, 'show'])->whereNumber('headerId');
            Route::get('customers/{headerId}/live', [CustomerController::class, 'live'])->whereNumber('headerId');
            Route::get('customers/{headerId}/bill-form', [BillController::class, 'form'])->whereNumber('headerId');
            Route::post('customers/{headerId}/bills', [BillController::class, 'store'])->whereNumber('headerId')->middleware('throttle:30,1');
            Route::get('daily-bills', [BillController::class, 'dailyBills']);
            Route::get('collections', [BillController::class, 'collections']);
            Route::get('receipts/collection/{collection}', [BillController::class, 'collectionReceipt'])->whereNumber('collection');
            Route::get('receipts/bill/{dailyBill}', [BillController::class, 'dailyBillReceipt'])->whereNumber('dailyBill');
        });
    });
});

<?php

use App\Http\Controllers\Api\{DepositController, TransferController, WalletTransactionController};
use App\Http\Middleware\ResolveUser;
use Illuminate\Support\Facades\Route;

// Endpoints are registered here: api.php -> controller -> use case.

Route::middleware(ResolveUser::class)->group(function () {
    Route::post('transfers', [TransferController::class, 'store']);
    Route::post('deposits', [DepositController::class, 'store']);
    Route::get('wallets/{wallet}/transactions', [WalletTransactionController::class, 'index'])->whereNumber('wallet');
});

// Customer browser return from the payment provider; deliberately outside user resolution.
Route::get('deposits/callback/{provider}', [DepositController::class, 'callback'])->where('provider', '[a-z0-9_]+');

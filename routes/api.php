<?php

use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::put(
        '/wallets/{wallet}',
        [WalletController::class, 'update']
    );

    Route::get(
        '/wallets/{wallet}/history',
        [WalletController::class, 'history']
    );

    Route::get(
        '/wallets/{wallet}/snapshot/{datetime}',
        [WalletController::class, 'snapshotAt']
    );

    Route::get(
        '/wallets/{wallet}/diff',
        [WalletController::class, 'diff']
    );
});
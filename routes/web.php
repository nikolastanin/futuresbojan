<?php

use App\Http\Controllers\FuturesController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard',        [FuturesController::class, 'index'])->name('dashboard');
    Route::get('pnl',              [FuturesController::class, 'pnlCalendar'])->name('pnl');
    Route::get('trading-history',  [FuturesController::class, 'tradingHistory'])->name('trading-history');
    Route::get('trading-journal',  [FuturesController::class, 'tradingJournal'])->name('trading-journal');

    Route::prefix('futures')->name('futures.')->group(function () {
        Route::get('account',    [FuturesController::class, 'account'])->name('account');
        Route::get('positions',  [FuturesController::class, 'positions'])->name('positions');
        Route::get('tickers',    [FuturesController::class, 'tickers'])->name('tickers');
        Route::get('today-pnl',      [FuturesController::class, 'todayPnl'])->name('today-pnl');
        Route::get('debug-history',  [FuturesController::class, 'debugHistory'])->name('debug-history');
        Route::post('orders',    [FuturesController::class, 'placeOrders'])->name('orders');
        Route::post('close',     [FuturesController::class, 'closePosition'])->name('close');
        Route::post('flash-close', [FuturesController::class, 'flashClose'])->name('flash-close');
        Route::post('close-all',       [FuturesController::class, 'closeAll'])->name('close-all');
        Route::post('stop-break-even',    [FuturesController::class, 'stopBreakEven'])->name('stop-break-even');
        Route::post('journal/regenerate', [FuturesController::class, 'regenerateJournal'])->name('journal.regenerate');
    });
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\BotLogsController;
use App\Http\Controllers\BotSettingsController;
use App\Http\Controllers\BotSignalsController;
use App\Http\Controllers\BotStatsController;
use App\Http\Controllers\FuturesController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard',        [FuturesController::class, 'index'])->name('dashboard');
    Route::post('dashboard/notes', [FuturesController::class, 'updateNotes'])->name('dashboard.notes.update');
    Route::get('trading-history',  [FuturesController::class, 'tradingHistory'])->name('trading-history');

    Route::prefix('bot')->name('bot.')->group(function () {
        Route::get('settings',  [BotSettingsController::class, 'index'])->name('settings');
        Route::post('settings', [BotSettingsController::class, 'update'])->name('settings.update');
        Route::post('positions/{trade}/close', [BotSettingsController::class, 'closePosition'])->name('positions.close');
        Route::get('stats',     [BotStatsController::class, 'index'])->name('stats');
        Route::get('signals',   [BotSignalsController::class, 'index'])->name('signals');
        Route::get('logs',      [BotLogsController::class, 'index'])->name('logs');
        Route::get('heartbeat', [BotLogsController::class, 'heartbeat'])->name('heartbeat');
    });

    Route::prefix('manual')->name('manual.')->group(function () {
        Route::post('settings', [FuturesController::class, 'updateManualSettings'])->name('settings.update');
        Route::get('positions',  [FuturesController::class, 'manualPositions'])->name('positions.index');
        Route::post('positions/{trade}/close', [FuturesController::class, 'closePaperPosition'])->name('positions.close');
        Route::post('positions/{trade}/set-sl-tp', [FuturesController::class, 'setPaperSlTp'])->name('positions.set-sl-tp');
    });

    Route::prefix('futures')->name('futures.')->group(function () {
        Route::get('account',    [FuturesController::class, 'account'])->name('account');
        Route::get('positions',  [FuturesController::class, 'positions'])->name('positions');
        Route::get('tickers',    [FuturesController::class, 'tickers'])->name('tickers');
        Route::get('signal-preview', [FuturesController::class, 'signalPreview'])->name('signal-preview');
        Route::get('top-signals', [FuturesController::class, 'topSignals'])->name('top-signals');
        Route::get('liquidity-hunt', [FuturesController::class, 'liquidityHunt'])->name('liquidity-hunt');
        Route::get('today-pnl',      [FuturesController::class, 'todayPnl'])->name('today-pnl');
        Route::get('debug-history',  [FuturesController::class, 'debugHistory'])->name('debug-history');
        Route::post('orders',    [FuturesController::class, 'placeOrders'])->name('orders');
        Route::post('less-is-more', [FuturesController::class, 'lessIsMore'])->name('less-is-more');
        Route::post('close',     [FuturesController::class, 'closePosition'])->name('close');
        Route::post('flash-close', [FuturesController::class, 'flashClose'])->name('flash-close');
        Route::post('close-all',       [FuturesController::class, 'closeAll'])->name('close-all');
        Route::post('stop-break-even',    [FuturesController::class, 'stopBreakEven'])->name('stop-break-even');
        Route::post('set-sl-tp',          [FuturesController::class, 'setSlTp'])->name('set-sl-tp');
    });
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\FuturesController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard',        [FuturesController::class, 'index'])->name('dashboard');
    Route::get('trading-history',  [FuturesController::class, 'tradingHistory'])->name('trading-history');

    Route::prefix('manual')->name('manual.')->group(function () {
        Route::post('settings', [FuturesController::class, 'updateManualSettings'])->name('settings.update');
        Route::get('positions',  [FuturesController::class, 'manualPositions'])->name('positions.index');
        Route::post('positions/{trade}/close', [FuturesController::class, 'closePaperPosition'])->name('positions.close');
        Route::post('positions/{trade}/set-sl-tp', [FuturesController::class, 'setPaperSlTp'])->name('positions.set-sl-tp');
    });

    Route::prefix('futures')->name('futures.')->group(function () {
        Route::get('account',    [FuturesController::class, 'account'])->name('account');
        Route::get('positions',  [FuturesController::class, 'positions'])->name('positions');
        Route::post('position-locks/toggle', [FuturesController::class, 'togglePositionLock'])->name('position-locks.toggle');
        Route::get('tickers',    [FuturesController::class, 'tickers'])->name('tickers');
        Route::get('symbols',    [FuturesController::class, 'symbols'])->name('symbols');
        Route::get('signal-preview', [FuturesController::class, 'signalPreview'])->name('signal-preview');
        Route::get('analysis-extras', [FuturesController::class, 'analysisExtras'])->name('analysis-extras');
        Route::post('equity-memory', [FuturesController::class, 'equityMemory'])->name('equity-memory');
        Route::post('snapshot', [FuturesController::class, 'recordSnapshot'])->middleware('throttle:30,1')->name('snapshot');
        Route::get('equity-today', [FuturesController::class, 'equityToday'])->name('equity-today');
        Route::post('ai-read', [FuturesController::class, 'aiRead'])->middleware('throttle:10,1')->name('ai-read');
        Route::post('ai-candles', [FuturesController::class, 'aiCandles'])->middleware('throttle:10,1')->name('ai-candles');
        Route::get('daily-grade', [FuturesController::class, 'dailyGrade'])->name('daily-grade');
        Route::post('daily-grade/coach', [FuturesController::class, 'dailyGradeCoach'])->middleware('throttle:6,1')->name('daily-grade.coach');
        Route::get('today-pnl',      [FuturesController::class, 'todayPnl'])->name('today-pnl');
        Route::get('pnl-calendar',   [FuturesController::class, 'pnlCalendar'])->name('pnl-calendar');
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

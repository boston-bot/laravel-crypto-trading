<?php

use App\Http\Controllers\OperationsActionController;
use App\Http\Controllers\OperationsConsolePageController;
use App\Http\Controllers\TradeDecisionApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('operations.local')->group(function (): void {
    Route::get('/dashboard', [OperationsConsolePageController::class, 'overview'])->name('console.overview');
    Route::get('/strategies', [OperationsConsolePageController::class, 'strategies'])->name('console.strategies');
    Route::get('/assets/{symbol?}', [OperationsConsolePageController::class, 'assets'])->name('console.assets');
    Route::get('/activity', [OperationsConsolePageController::class, 'activity'])->name('console.activity');
    Route::get('/paper', [OperationsConsolePageController::class, 'paper'])->name('console.paper');
    Route::get('/operations', [OperationsConsolePageController::class, 'operations'])->name('console.operations');
    Route::get('/research', [OperationsConsolePageController::class, 'research'])->name('console.research');

    Route::prefix('operations/actions')->group(function (): void {
        Route::post('/cycles', [OperationsActionController::class, 'cycle']);
        Route::post('/sync', [OperationsActionController::class, 'sync']);
        Route::post('/paper-sessions', [OperationsActionController::class, 'startPaperSession']);
        Route::post('/paper-sessions/{paperSession}/end', [OperationsActionController::class, 'endPaperSession']);
        Route::post('/runtime', [OperationsActionController::class, 'runtime']);
        Route::post('/trade-decisions/{tradeDecision}/approve', [TradeDecisionApprovalController::class, 'approve']);
        Route::post('/trade-decisions/{tradeDecision}/reject', [TradeDecisionApprovalController::class, 'reject']);
    });
});

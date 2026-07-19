<?php

use App\Http\Controllers\Api\V1\OperationsConsoleDataController;
use App\Http\Controllers\BrokerDataController;
use App\Http\Controllers\PerformanceDashboardController;
use App\Http\Controllers\ResearchDataController;
use App\Http\Controllers\TradeDecisionApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware('operations.local')->prefix('trade-decisions')->group(function (): void {
    Route::get('/pending', [TradeDecisionApprovalController::class, 'indexPending']);
    Route::post('/{tradeDecision}/approve', [TradeDecisionApprovalController::class, 'approve']);
    Route::post('/{tradeDecision}/reject', [TradeDecisionApprovalController::class, 'reject']);
});

Route::middleware('operations.local')->prefix('broker')->group(function (): void {
    Route::get('/overview', [BrokerDataController::class, 'overview']);
    Route::get('/market-data/quotes', [BrokerDataController::class, 'marketQuotes']);
    Route::get('/market-data/candles', [BrokerDataController::class, 'marketCandles']);
    Route::get('/performance-dashboard', PerformanceDashboardController::class);
    Route::get('/accounts', [BrokerDataController::class, 'accounts']);
    Route::get('/accounts/{brokerAccount}', [BrokerDataController::class, 'account']);
    Route::get('/accounts/{brokerAccount}/positions', [BrokerDataController::class, 'accountPositions']);
    Route::get('/accounts/{brokerAccount}/paper-positions', [BrokerDataController::class, 'paperPositions']);
    Route::get('/accounts/{brokerAccount}/paper-performance', [BrokerDataController::class, 'paperPerformance']);
    Route::get('/accounts/{brokerAccount}/orders', [BrokerDataController::class, 'accountOrders']);
    Route::get('/assets', [BrokerDataController::class, 'assets']);
    Route::get('/trade-decisions', [BrokerDataController::class, 'tradeDecisions']);
    Route::get('/risk-events', [BrokerDataController::class, 'riskEvents']);
    Route::post('/sync', [BrokerDataController::class, 'sync']);
});

Route::middleware('operations.local')->prefix('research')->group(function (): void {
    Route::get('/data-health', [ResearchDataController::class, 'dataHealth']);
    Route::get('/backtests', [ResearchDataController::class, 'backtests']);
    Route::get('/calibration', [ResearchDataController::class, 'calibration']);
    Route::get('/spreads', [ResearchDataController::class, 'spreads']);
});

Route::middleware('operations.local')->prefix('ops/v1')->group(function (): void {
    Route::get('/overview', [OperationsConsoleDataController::class, 'overview']);
    Route::get('/strategies', [OperationsConsoleDataController::class, 'strategies']);
    Route::get('/assets', [OperationsConsoleDataController::class, 'assets']);
    Route::get('/activity', [OperationsConsoleDataController::class, 'activity']);
    Route::get('/paper', [OperationsConsoleDataController::class, 'paper']);
    Route::get('/operations', [OperationsConsoleDataController::class, 'operations']);
    Route::get('/research', [OperationsConsoleDataController::class, 'research']);
    Route::get('/actions/{operatorAction}', [OperationsConsoleDataController::class, 'action']);
});

<?php

use App\Http\Controllers\BrokerDataController;
use App\Http\Controllers\TradeDecisionApprovalController;
use Illuminate\Support\Facades\Route;

Route::prefix('trade-decisions')->group(function (): void {
    Route::get('/pending', [TradeDecisionApprovalController::class, 'indexPending']);
    Route::post('/{tradeDecision}/approve', [TradeDecisionApprovalController::class, 'approve']);
    Route::post('/{tradeDecision}/reject', [TradeDecisionApprovalController::class, 'reject']);
});

Route::prefix('broker')->group(function (): void {
    Route::get('/overview', [BrokerDataController::class, 'overview']);
    Route::get('/market-data/quotes', [BrokerDataController::class, 'marketQuotes']);
    Route::get('/market-data/candles', [BrokerDataController::class, 'marketCandles']);
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

<?php

use App\Http\Controllers\ControlCenterController;
use App\Http\Controllers\ExperimentController;
use App\Http\Controllers\LocalSessionController;
use App\Http\Controllers\McpConnectionController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OperationsSetupController;
use App\Http\Controllers\OperatorActionController;
use App\Http\Controllers\PaperExecutionController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\ResearchController;
use App\Http\Middleware\FinancialAccess;
use App\Http\Middleware\McpResponsePrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(base_path('routes/api.php'));

Route::redirect('/', '/mcp/dashboard');

Route::get('/login', [LocalSessionController::class, 'create'])->name('login');
Route::post('/login', [LocalSessionController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [LocalSessionController::class, 'destroy'])->middleware('auth');
Route::get('/ready', ReadinessController::class);

Route::prefix('mcp/robinhood')->middleware([McpResponsePrivacy::class, 'auth', FinancialAccess::class.':operate-finances'])->group(function (): void {
    Route::get('/', [McpConnectionController::class, 'show'])->name('mcp.connection');
    Route::post('/connect', [McpConnectionController::class, 'connect'])->middleware('throttle:mcp-connect')->name('mcp.connect');
    Route::get('/callback', [McpConnectionController::class, 'callback'])->name('mcp.callback');
    Route::post('/renew', [McpConnectionController::class, 'renew'])->middleware('throttle:mcp-renew')->name('mcp.renew');
    Route::post('/discover', [McpConnectionController::class, 'discover'])->middleware('throttle:mcp-broker-read')->name('mcp.discover');
    Route::post('/accounts', [McpConnectionController::class, 'accounts'])->middleware('throttle:mcp-broker-read')->name('mcp.accounts');
    Route::post('/bind', [McpConnectionController::class, 'bind'])->middleware('throttle:mcp-broker-read')->name('mcp.bind');
    Route::get('/reads/{operation}', [McpConnectionController::class, 'read'])->middleware('throttle:mcp-broker-read')->name('mcp.read');
    Route::get('/watchlists/read', [McpConnectionController::class, 'watchlist'])->middleware('throttle:5,1')->name('mcp.watchlist.read');
});

Route::prefix('mcp')->middleware([McpResponsePrivacy::class, 'auth', FinancialAccess::class.':operate-finances'])->group(function (): void {
    Route::get('/experiments', [ExperimentController::class, 'index'])->name('mcp.experiments.index');
    Route::post('/experiments', [ExperimentController::class, 'store'])->middleware('throttle:10,1')->name('mcp.experiments.store');
    Route::get('/experiments/{experiment}', [ExperimentController::class, 'show'])->whereUuid('experiment')->name('mcp.experiments.show');
    Route::get('/experiments/{experiment}/report', [ExperimentController::class, 'report'])->whereUuid('experiment')->name('mcp.experiments.report');
    Route::post('/experiments/{experiment}/resume', [ExperimentController::class, 'resume'])->whereUuid('experiment')->middleware('throttle:10,1')->name('mcp.experiments.resume');
    Route::get('/dashboard/{page?}', [MonitoringController::class, 'show'])->name('mcp.dashboard');
    Route::post('/control/mandate', [ControlCenterController::class, 'store'])->middleware('throttle:10,1')->name('mcp.control.store');
    Route::post('/control/strategy-watchlist', [ControlCenterController::class, 'watchlist'])->middleware('throttle:10,1')->name('mcp.control.watchlist');
    Route::post('/control/eligibility/apply', [ControlCenterController::class, 'applyEligibility'])->middleware('throttle:10,1')->name('mcp.control.eligibility.apply');
    Route::post('/control/eligibility/assess', [ControlCenterController::class, 'assessEligibility'])->middleware('throttle:10,1')->name('mcp.control.eligibility.assess');
    Route::post('/control/external-orders/{order}/review', [ControlCenterController::class, 'reviewExternalOrder'])->whereNumber('order')->middleware('throttle:10,1')->name('mcp.control.external-order.review');
    Route::post('/control/proposal', [ControlCenterController::class, 'proposal'])->middleware('throttle:10,1')->name('mcp.control.proposal');
    Route::post('/control/versions/{version}/restore', [ControlCenterController::class, 'restore'])->whereNumber('version')->middleware('throttle:10,1')->name('mcp.control.restore');
    Route::post('/control/versions/{version}/apply-paper', [ControlCenterController::class, 'apply'])->whereNumber('version')->middleware('throttle:10,1')->name('mcp.control.apply');
    Route::post('/paper/signal-execution/apply', [PaperExecutionController::class, 'applySignalPolicy'])->middleware('throttle:10,1')->name('mcp.paper.signal-execution.apply');
    Route::post('/paper/execution', [PaperExecutionController::class, 'store'])->middleware('throttle:10,1')->name('mcp.paper.execution.store');
    Route::post('/paper/execution/{version}/apply', [PaperExecutionController::class, 'apply'])->whereNumber('version')->middleware('throttle:10,1')->name('mcp.paper.execution.apply');
    Route::post('/paper/market-input', [PaperExecutionController::class, 'collect'])->middleware('throttle:5,1')->name('mcp.paper.market.collect');
    Route::post('/paper/decisions/{run}/orders', [PaperExecutionController::class, 'handoff'])->whereUuid('run')->middleware('throttle:5,1')->name('mcp.paper.handoff');
    Route::post('/operations/phone-setup', [OperationsSetupController::class, 'phone'])->middleware('throttle:10,1')->name('mcp.operations.phone');
    Route::post('/operations/health-check', [OperatorActionController::class, 'health'])->middleware('throttle:6,1')->name('mcp.operations.health');
    Route::post('/operations/test-alert', [OperatorActionController::class, 'testAlert'])->middleware('throttle:3,1')->name('mcp.operations.test-alert');
    Route::post('/operations/summary', [OperatorActionController::class, 'summary'])->middleware('throttle:6,1')->name('mcp.operations.summary');
    Route::post('/operations/database-check', [OperatorActionController::class, 'database'])->middleware('throttle:6,1')->name('mcp.operations.database');
    Route::post('/dashboard/sync', [MonitoringController::class, 'sync'])->middleware('throttle:10,1')->name('mcp.dashboard.sync');
    Route::post('/incidents/{incident}/acknowledge', [MonitoringController::class, 'acknowledge'])->name('mcp.incident.ack');
    Route::post('/research/evidence', [ResearchController::class, 'evidence'])->middleware('throttle:20,1')->name('mcp.research.evidence');
    Route::post('/research/request', [ResearchController::class, 'request'])->middleware('throttle:10,1')->name('mcp.research.request');
    Route::post('/research/watchlist', [ResearchController::class, 'watch'])->name('mcp.research.watch');
    Route::post('/research/watchlist/{item}/remove', [ResearchController::class, 'unwatch'])->whereNumber('item')->name('mcp.research.unwatch');
    Route::post('/research/runs/{run}/adopt', [ResearchController::class, 'adopt'])->name('mcp.research.adopt');
    Route::post('/theses', [ResearchController::class, 'thesis'])->name('mcp.thesis.store');
});

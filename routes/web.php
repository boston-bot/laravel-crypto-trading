<?php

use App\Http\Controllers\LocalSessionController;
use App\Http\Controllers\McpConnectionController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OperationsSetupController;
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
    Route::post('/discover', [McpConnectionController::class, 'discover'])->name('mcp.discover');
    Route::post('/accounts', [McpConnectionController::class, 'accounts'])->name('mcp.accounts');
    Route::post('/bind', [McpConnectionController::class, 'bind'])->name('mcp.bind');
    Route::get('/reads/{operation}', [McpConnectionController::class, 'read'])->name('mcp.read');
});

Route::prefix('mcp')->middleware([McpResponsePrivacy::class, 'auth', FinancialAccess::class.':operate-finances'])->group(function (): void {
    Route::get('/dashboard/{page?}', [MonitoringController::class, 'show'])->name('mcp.dashboard');
    Route::post('/operations/phone-setup', [OperationsSetupController::class, 'phone'])->middleware('throttle:10,1')->name('mcp.operations.phone');
    Route::post('/dashboard/sync', [MonitoringController::class, 'sync'])->middleware('throttle:10,1')->name('mcp.dashboard.sync');
    Route::post('/incidents/{incident}/acknowledge', [MonitoringController::class, 'acknowledge'])->name('mcp.incident.ack');
    Route::post('/research/evidence', [ResearchController::class, 'evidence'])->middleware('throttle:20,1')->name('mcp.research.evidence');
    Route::post('/research/request', [ResearchController::class, 'request'])->middleware('throttle:10,1')->name('mcp.research.request');
    Route::post('/research/watchlist', [ResearchController::class, 'watch'])->name('mcp.research.watch');
    Route::post('/research/watchlist/{item}/remove', [ResearchController::class, 'unwatch'])->whereNumber('item')->name('mcp.research.unwatch');
    Route::post('/research/runs/{run}/adopt', [ResearchController::class, 'adopt'])->name('mcp.research.adopt');
    Route::post('/theses', [ResearchController::class, 'thesis'])->name('mcp.thesis.store');
});

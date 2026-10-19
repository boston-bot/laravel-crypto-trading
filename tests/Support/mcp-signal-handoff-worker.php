<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
$c = McpConnection::findOrFail((int) $argv[1]);
config(['paper.signals_enabled' => true, 'paper.signal_handoff_enabled' => true, 'paper.real_data_fills_enabled' => true,
    'paper.rule_eligibility_enabled' => true, 'paper.watchlist_intake_enabled' => true, 'mcp.external_order_detection_enabled' => true,
    'paper.instrument_classification_enabled' => true, 'paper.corporate_actions_enabled' => true, 'robinhood_mcp.earnings_enabled' => true,
    'alpaca_data.reference_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($c->user_id)->email]]);
$r = app(SignalPaperHandoff::class)->process($c, $c->user_id, (int) $argv[2]);
echo $r->id.':'.$r->status.':'.$r->sim_order_id;

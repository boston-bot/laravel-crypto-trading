<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\StrategySignals;
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
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[2]));
Carbon::setTestNow(CarbonImmutable::parse($argv[2]));
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['paper.signals_enabled' => true, 'paper.symbol_profiles_enabled' => true, 'paper.rule_eligibility_enabled' => true,
    'paper.watchlist_intake_enabled' => true, 'mcp.external_order_detection_enabled' => true, 'paper.instrument_classification_enabled' => true,
    'paper.corporate_actions_enabled' => true, 'robinhood_mcp.earnings_enabled' => true, 'alpaca_data.reference_enabled' => true,
    'mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
echo app(StrategySignals::class)->record($connection, $connection->user_id, ! empty($argv[3]) ? explode(',', $argv[3]) : null)->map(fn ($r) => $r->id.':'.$r->status.':'.$r->side)->implode(',');

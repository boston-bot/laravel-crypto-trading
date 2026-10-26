<?php

use App\Models\User;
use App\Services\Crypto\CryptoPolicies;
use App\Services\Crypto\CryptoStrategyEvaluation;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Dedicated PostgreSQL fixture worker. Never resolves or uses configured brokerage credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), null);
Http::preventStrayRequests();
$actor = (int) $argv[2];
$user = User::findOrFail($actor);
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[4]));
Carbon::setTestNow(CarbonImmutable::parse($argv[4]));
config(['mcp.operator_emails' => [$user->email], 'crypto.strategy_evaluation_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
if ($argv[1] === 'policy') {
    echo app(CryptoPolicies::class)->apply($actor, $argv[3])->id;
} elseif ($argv[1] === 'paper') {
    config(['crypto.paper_enabled' => true]);
    echo app(CryptoStrategyEvaluation::class)->evaluate($actor, (int) $argv[3])['cycle_id'];
} else {
    echo app(CryptoStrategyEvaluation::class)->evaluate($actor, (int) $argv[3])['cycle_id'];
}

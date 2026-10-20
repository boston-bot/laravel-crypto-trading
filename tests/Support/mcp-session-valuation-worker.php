<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperSessionValuations;
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
$c = McpConnection::findOrFail((int) $argv[1]);
config(['paper.session_valuations_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($c->user_id)->email]]);
$result = app(PaperSessionValuations::class)->capture($c, $c->user_id);
echo $result['status'].':'.($result['valuation']->id ?? $result['reason']);

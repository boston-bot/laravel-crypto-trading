<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\RuleEligibility;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[4]));
Carbon::setTestNow(CarbonImmutable::parse($argv[4]));
Http::preventStrayRequests();
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
try {
    if ($argv[2] === 'policy') {
        echo app(RuleEligibilityPolicies::class)->apply($connection, $connection->user_id, 0, $argv[3], RuleEligibilityPolicies::DEFAULTS)->id;
    } else {
        echo app(RuleEligibility::class)->record($connection, $connection->user_id, 'DEMO', $argv[3], CarbonImmutable::now())->id;
    }
} catch (Throwable) {
    fwrite(STDERR, 'Eligibility fixture worker failed.');
    exit(1);
}

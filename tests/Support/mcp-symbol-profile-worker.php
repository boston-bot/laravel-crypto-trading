<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\SymbolProfiles;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
config(['paper.symbol_profiles_enabled' => true]); // Stored history only; no provider or broker call is possible here.
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
echo app(SymbolProfiles::class)->record($connection, $connection->user_id, [], $argv[2], CarbonImmutable::parse($argv[3]))->pluck('id')->implode(',');

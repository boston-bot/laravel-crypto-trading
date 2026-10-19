<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperInstrumentClassifications;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\EdgarFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
EdgarFixture::enable();
EdgarFixture::fake(EdgarFixture::plainFund()); // Synthetic public-filing responses; stray requests are rejected.
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
echo app(PaperInstrumentClassifications::class)->collect($connection, $connection->user_id, [], $argv[2])->sole()->id;

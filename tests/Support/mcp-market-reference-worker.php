<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlpacaReferenceFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
AlpacaReferenceFixture::enable();
AlpacaReferenceFixture::fake(fn () => AlpacaReferenceFixture::body(['DEMO' => AlpacaReferenceFixture::bars(260)]), ['DEMO' => AlpacaReferenceFixture::asset('DEMO')]); // Synthetic provider responses; stray requests are rejected.
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
echo app(PaperMarketReferences::class)->collect($connection, $connection->user_id, [], $argv[2])->pluck('id')->implode(',');

<?php

use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Broker\Agentic\Operation;
use App\Services\Monitoring\ExternalOrders;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalOrderGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[5]));
Carbon::setTestNow(CarbonImmutable::parse($argv[5]));
Http::preventStrayRequests();
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['mcp.external_order_detection_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
if ($argv[2] === 'record') {
    $gateway = new ExternalOrderGateway;
    $gateway->orders = [['id' => 'concurrent-external-'.$argv[3]]];
    $read = json_decode(json_encode($gateway->read($connection, Operation::Orders), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    app(ExternalOrders::class)->record($connection, Snapshot::findOrFail((int) $argv[3]), $read, $argv[4]);
    echo 'recorded';
} else {
    echo app(ExternalOrders::class)->review($connection, $connection->user_id, (int) $argv[3], $argv[4], 'Concurrent owner review.')->id;
}

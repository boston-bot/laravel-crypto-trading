<?php

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Support\Mcp\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MonitoringGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
TestDatabaseGuard::check($connection->getDriverName(), $connection->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
$gateway = new MonitoringGateway;
$gateway->beforeRead = function ($connection, $operation) use ($argv) {
    file_put_contents($argv[2], $operation->value."\n", FILE_APPEND | LOCK_EX);
    usleep(150000);
};
$app->instance(BrokerGateway::class, $gateway);
$run = app(Monitor::class)->sync((int) $argv[1], 'concurrent:fixture');
echo $run->id;

<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\ExecutionPolicies;
use App\Services\PaperTrading\PaperOrderHandoff;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
$connection = McpConnection::findOrFail((int) $argv[1]);
config(['paper.handoff_enabled' => true, 'paper.offline_simulation_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
$data = json_decode(base64_decode($argv[2], true), true, 16, JSON_THROW_ON_ERROR);
try {
    if ($data['operation'] === 'handoff') {
        echo app(PaperOrderHandoff::class)->process($connection, $connection->user_id, $data['run_id'])->sole()->status;
    } else {
        app(ExecutionPolicies::class)->save($connection, $connection->user_id, 0, $data['request_id'], $data['payload'], 'Synthetic competing draft.');
        echo 'saved';
    }
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'conflict';
}

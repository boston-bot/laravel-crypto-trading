<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\PaperCorporateActions;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\SimulationAssumptions;
use App\Services\PaperTrading\SimulationQuote;
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
config(['paper.offline_simulation_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
$data = json_decode(base64_decode($argv[2], true), true, 16, JSON_THROW_ON_ERROR);
$simulator = app(OfflineSimulator::class);
try {
    if ($data['operation'] === 'actions') {
        config(['paper.corporate_actions_enabled' => true]);
        $records = app(PaperCorporateActions::class)->process($connection, $connection->user_id, $data['input_id']);
        echo $records->last()?->status ?? 'empty';
        exit(0);
    }
    if ($data['operation'] === 'real_observe') {
        config(['paper.real_data_fills_enabled' => true, 'paper.offline_simulation_enabled' => false]);
        $order = app(RealDataPaperFills::class)->process($connection, $connection->user_id, $data['order_id']);
        echo $order->status;
        exit(0);
    }
    $order = $data['operation'] === 'submit'
        ? $simulator->submit($connection, $connection->user_id, $data['policy_id'], $data['ledger_version'], $data['intent'],
            new SimulationAssumptions($data['assumptions']), ['DEMO' => new SimulationQuote($data['quote'])])
        : $simulator->observe($connection, $connection->user_id, $data['order_id'], new SimulationQuote($data['quote']));
    echo $order->status;
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'conflict';
}

<?php

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\User;
use App\Services\PaperTrading\DecisionInputs;
use App\Services\PaperTrading\DecisionRunner;
use App\Services\Research\ResearchModel;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDecisionInputs;
use Tests\Support\FakeDecisionModel;
use Tests\Support\ResearchFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[3]));
Carbon::setTestNow(CarbonImmutable::parse($argv[3]));
$run = DecisionRun::findOrFail($argv[1]);
$connection = McpConnection::findOrFail($run->mcp_connection_id);
ResearchFixture::enable(json_decode($argv[4], true));
config(['paper.decisions_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
$model = new FakeDecisionModel;
$model->log = $argv[2];
$model->sleepMs = 1000;
$model->respond = function ($request) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Model called inside a database transaction.');
    }

    return FakeDecisionModel::answer($request);
};
$app->instance(ResearchModel::class, $model);
$app->instance(DecisionInputs::class, new FakeDecisionInputs);
echo app(DecisionRunner::class)->process($run->id)?->status ?? 'none';

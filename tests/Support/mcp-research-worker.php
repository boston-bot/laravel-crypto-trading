<?php

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Research\ResearchModel;
use App\Services\Research\ResearchRunner;
use App\Support\Mcp\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResearchModel;
use Tests\Support\MonitoringGateway;
use Tests\Support\ResearchFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
TestDatabaseGuard::check($connection->getDriverName(), $connection->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
ResearchFixture::enable(json_decode($argv[3], true));
$model = new FakeResearchModel;
$model->log = $argv[2];
$model->sleepMs = 700;
$model->reportUsage = false; // Settles at the full reservation, so the outcome does not depend on scheduling order.
$app->instance(ResearchModel::class, $model);
$app->instance(BrokerGateway::class, new MonitoringGateway);
$run = app(ResearchRunner::class)->process((int) $argv[1]);
echo $run?->status ?? 'none';

<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\Experiments\StrategyExperiments;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExperimentFixture as F;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), 'postgres');
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
Carbon::setTestNow(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
$c = McpConnection::findOrFail((int) $argv[1]);
F::enable();
config(['mcp.operator_emails' => [User::findOrFail($c->user_id)->email]]);
$runner = app(StrategyExperiments::class);
if ($argv[2] === 'start') {
    echo $runner->start($c, $c->user_id, $argv[3], F::spec())->experiment_id;
} else {
    $waited = false;
    F::sources(function () use (&$waited) {
        if (! $waited) {
            $waited = true;
            usleep(1000000); // Hold the network phase open so competing workers must observe the lease.
        }

        return null;
    });
    echo $runner->work((int) $argv[3]);
}

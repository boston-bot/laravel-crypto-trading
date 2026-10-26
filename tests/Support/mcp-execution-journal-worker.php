<?php

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\SubmissionChecks;
use App\Support\Mcp\TestDatabaseGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExecutionJournalFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::check(DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
CarbonImmutable::setTestNow(CarbonImmutable::parse($argv[4]));
Carbon::setTestNow(CarbonImmutable::parse($argv[4]));
$c = McpConnection::findOrFail((int) $argv[2]);
config(['mcp.operator_emails' => [User::findOrFail($c->user_id)->email], 'mcp.brokerage_writes_enabled' => true]);
// Journal race test only: no broker adapter exists and no HTTP client is allowed.
$journal = new ExecutionJournal(new class extends SubmissionChecks
{
    public function reason(McpConnection $connection, OrderIntent $intent): ?string
    {
        return null;
    }
});
if ($argv[1] === 'stage') {
    echo $journal->stage($c, $c->user_id, (int) $argv[3], ExecutionJournalFixture::draft())->id;
} else {
    echo $journal->claim($c, $c->user_id, (int) $argv[3]) ?? 'not_claimed';
}

<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Execution\AutomaticTrading;
use App\Services\Execution\ExecutionDispatcher;
use App\Services\Execution\ExecutionGateway;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\ExecutionRecoveryFence;
use App\Services\Monitoring\AccountScope;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\Backups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AutomaticTradingFixture;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\FakeExecutionGateway;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\TestCase;

/** Real encrypted dump/restore; synthetic account and fake broker only. */
class LiveExecutionRestoreTest extends TestCase
{
    public function test_a_database_restored_before_the_owner_stop_cannot_restore_active_automation(): void
    {
        [$c] = F::seed(new LiveBrokerGateway);
        $this->fixture = $c->id;
        AutomaticTradingFixture::configure();
        app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), true, 0, true);
        app(Backups::class)->run();
        app(AutomaticTrading::class)->set($c, $c->user_id, (string) Str::uuid(), false, 0);
        $checked = false;
        $verify = function ($restored) use ($c, &$checked): void {
            $this->assertStringStartsWith('mcp_trading_restore_drill_', $restored->getDatabaseName());
            $original = config('database.default');
            try {
                DB::setDefaultConnection('restore_drill');
                $connection = McpConnection::findOrFail($c->id);
                $scope = AccountScope::key($connection);
                $this->assertTrue(AutomaticTrading::current($scope)->enabled, 'The restored DB really predates OFF.');
                $this->assertSame('execution_recovery_automation_changed', app(AutomaticTrading::class)->reason($connection));
                $this->assertSame('execution_recovery_automation_changed', app(ExecutionRecoveryFence::class)->reason($scope));
                $checked = true;
            } finally {
                DB::setDefaultConnection($original);
            }
        };
        $drill = new class($verify) extends Backups
        {
            public function __construct(private \Closure $verify) {}

            protected function counts($connection): array
            {
                $counts = parent::counts($connection);
                if ($connection->getName() === 'restore_drill') {
                    ($this->verify)($connection);
                }

                return $counts;
            }
        };
        $this->assertTrue($drill->restoreDrill()['scratch_database_dropped']);
        $this->assertTrue($checked);
    }

    private ?int $fixture = null;

    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        if (! is_executable(rtrim(config('operations.backup.pg_bin'), '/').'/pg_dump')) {
            $this->markTestSkipped('PostgreSQL client binaries unavailable.');
        }
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->dir = sys_get_temp_dir().'/mcp-live-restore-test-'.bin2hex(random_bytes(6));
        config(['operations.backup.dir' => $this->dir, 'operations.backup.key_file' => $this->dir.'/keys/backup.key', 'operations.backup.offsite_dir' => null]);
        BackupCipher::createKey($this->dir.'/keys/backup.key');
    }

    protected function tearDown(): void
    {
        ExecutionJournalFixture::cleanup($this->fixture);
        if ($this->dir) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    public static function inFlightStates(): array
    {
        return [['before_intents'], ['staged'], ['submitting'], ['accepted']];
    }

    #[DataProvider('inFlightStates')]
    public function test_restored_in_flight_intents_cannot_be_resent_or_allow_another_claim(string $state): void
    {
        $broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($broker);
        $this->fixture = $c->id;
        $gateway = new FakeExecutionGateway;
        app()->instance(ExecutionGateway::class, $gateway);
        config(['mcp.brokerage_writes_enabled' => true]); // Isolated test and fake gateway only.
        if ($state === 'before_intents') {
            app(Backups::class)->run();
        }
        $first = F::stage($c, $signal);
        $second = F::stage($c, F::signal($c, 'BUY'));
        if ($state === 'staged') {
            app(Backups::class)->run();
        }
        $journal = app(ExecutionJournal::class);
        $token = $journal->claim($c, $c->user_id, $first->id);
        $this->assertNotNull($token);
        if ($state === 'accepted') {
            $journal->accepted($c, $c->user_id, $first->id, $token, 'synthetic-before-backup');
        }
        if (in_array($state, ['submitting', 'accepted'], true)) {
            app(Backups::class)->run();
        }
        // The source advances after the snapshot. Restoration must honor the older unresolved state.
        if ($state !== 'accepted') {
            $journal->accepted($c, $c->user_id, $first->id, $token, 'synthetic-after-backup');
        } else {
            $this->travel(1)->seconds();
            $broker->orders = [LiveBrokerGateway::order('synthetic-before-backup', 'filled', $first->payload['quantity'],
                $first->payload['quantity'], [['synthetic-fill', $first->payload['quantity'], '101.70']])];
            $broker->positions['DEMO'] = [$first->payload['quantity'], '101.70'];
            F::sync($c, $broker);
            $this->assertSame('filled', DB::table('mcp_execution_dispatches')->where('intent_id', $first->id)->value('status'));
        }

        $verified = false;
        $verify = function ($restored) use ($c, $first, $second, $state, $gateway, &$verified): void {
            $this->assertStringStartsWith('mcp_trading_restore_drill_', $restored->getDatabaseName());
            $original = config('database.default');
            try {
                DB::setDefaultConnection('restore_drill');
                $restoredConnection = McpConnection::findOrFail($c->id);
                if (in_array($state, ['staged', 'before_intents'], true)) {
                    $queued = $state === 'staged' ? $first : F::stage($restoredConnection, StrategySignal::findOrFail($first->signal_id));
                    try {
                        app(ExecutionDispatcher::class)->dispatch($restoredConnection, $c->user_id, $queued->id);
                        $this->fail('A database older than its external checkpoint dispatched.');
                    } catch (HttpException $failure) {
                        $this->assertSame(409, $failure->getStatusCode());
                        $this->assertStringStartsWith('execution_recovery_', $failure->getMessage());
                    }
                    $this->assertSame('staged', DB::table('mcp_execution_dispatches')->where('intent_id', $queued->id)->value('status'));
                    $this->assertSame([], $gateway->calls);
                    $verified = true;

                    return;
                }
                $this->assertSame($state, DB::table('mcp_execution_dispatches')->where('intent_id', $first->id)->value('status'));
                $this->assertSame($first->idempotency_key, DB::table('mcp_execution_intents')->where('id', $first->id)->value('idempotency_key'));
                foreach ([$first, $second] as $intent) {
                    $this->assertSame('not_claimed', app(ExecutionDispatcher::class)->dispatch($restoredConnection, $c->user_id, $intent->id));
                }
                $this->assertSame('staged', DB::table('mcp_execution_dispatches')->where('intent_id', $second->id)->value('status'));
                $this->assertSame([], $gateway->calls);
                $verified = true;
            } finally {
                DB::setDefaultConnection($original);
            }
        };
        // Inspect the actual scratch restore during the normal count verification, without production hooks.
        $drill = new class($verify) extends Backups
        {
            public function __construct(private \Closure $verify) {}

            protected function counts($connection): array
            {
                $counts = parent::counts($connection);
                if ($connection->getName() === 'restore_drill') {
                    ($this->verify)($connection);
                }

                return $counts;
            }
        };
        $result = $drill->restoreDrill();
        $this->assertTrue($verified);
        $this->assertTrue($result['scratch_database_dropped']);
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS db')->db);
        $this->assertSame([], DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'mcp_trading_restore_drill_%'"));
    }
}

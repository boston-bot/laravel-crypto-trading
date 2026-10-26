<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\Execution\ExecutionDispatcher;
use App\Services\Execution\ExecutionGateway;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\ExecutionRecoveryFence;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\FakeExecutionGateway;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\Support\McpFixture;
use Tests\TestCase;

class ExecutionRecoveryFenceTest extends TestCase
{
    private ?int $fixture = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        ExecutionJournalFixture::cleanup($this->fixture);
        parent::tearDown();
    }

    private function ready(): array
    {
        [$c, $signal] = F::seed(new LiveBrokerGateway);
        $this->fixture = $c->id;
        config(['mcp.brokerage_writes_enabled' => true]);
        $gateway = new FakeExecutionGateway;
        app()->instance(ExecutionGateway::class, $gateway);

        return [$c, F::stage($c, $signal), $gateway, config('mcp.execution_recovery_dir').'/'.hash('sha256', AccountScope::key($c)).'.json'];
    }

    public function test_first_use_command_requires_owner_disabled_writes_and_never_overwrites(): void
    {
        $c = McpFixture::connection();
        $c->update(['bound_account_id' => 'synthetic-fence-'.Str::uuid()]);
        $this->fixture = $c->id;
        $args = ['--account' => $c->id, '--initialize' => true];
        config(['mcp.operator_emails' => []]);
        $this->artisan('mcp:execution-fence', $args)->assertFailed();
        config(['mcp.operator_emails' => [User::findOrFail($c->user_id)->email], 'mcp.brokerage_writes_enabled' => true]);
        $this->artisan('mcp:execution-fence', $args)->expectsOutput('execution_recovery_disable_writes_first')->assertFailed();
        config(['mcp.brokerage_writes_enabled' => false]);
        $this->artisan('mcp:execution-fence', $args)->assertSuccessful();
        $path = config('mcp.execution_recovery_dir').'/'.hash('sha256', AccountScope::key($c)).'.json';
        $original = file_get_contents($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->artisan('mcp:execution-fence', $args)->expectsOutput('execution_recovery_checkpoint_exists_or_unavailable')->assertFailed();
        $this->assertSame($original, file_get_contents($path));
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
    }

    public static function damagedCheckpoints(): array
    {
        return [['missing'], ['corrupt'], ['public'], ['symlink'], ['unconfigured']];
    }

    #[DataProvider('damagedCheckpoints')]
    public function test_unavailable_checkpoint_cannot_claim_or_recreate_itself(string $damage): void
    {
        [$c, $intent, $gateway, $path] = $this->ready();
        match ($damage) {
            'missing' => unlink($path),
            'corrupt' => file_put_contents($path, '{broken'),
            'public' => chmod($path, 0644),
            'symlink' => (function () use ($path) {
                rename($path, $path.'.target');
                symlink($path.'.target', $path);
            })(),
            'unconfigured' => config(['mcp.execution_recovery_dir' => null]),
        };
        try {
            app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id);
            $this->fail('Checkpoint damage was ignored.');
        } catch (HttpException $failure) {
            $this->assertSame(409, $failure->getStatusCode());
            $this->assertStringStartsWith('execution_recovery_', $failure->getMessage());
        }
        $this->assertSame([], $gateway->calls);
        $this->assertSame('staged', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
        if ($damage === 'missing') {
            $this->assertFileDoesNotExist($path);
        }
        config(['mcp.brokerage_writes_enabled' => false]);
        $this->artisan('mcp:execution-fence', ['--account' => $c->id, '--initialize' => true])
            ->expectsOutput('execution_recovery_existing_intents')->assertFailed();
    }

    public function test_checkpoint_damage_during_preview_withdraws_before_place(): void
    {
        [$c, $intent, $gateway, $path] = $this->ready();
        $gateway->beforeReview = fn () => unlink($path);
        $this->assertSame('withdrawn', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $this->assertSame(['open', 'review'], $gateway->calls);
        $this->assertSame('withdrawn_execution_recovery_checkpoint_unavailable', DB::table('mcp_execution_events')
            ->where('intent_id', $intent->id)->orderByDesc('version')->value('reason'));
    }

    public function test_manual_stage_command_requires_checkpoint_before_creating_any_intent(): void
    {
        [$c, $signal] = F::seed(new LiveBrokerGateway);
        $this->fixture = $c->id;
        $path = config('mcp.execution_recovery_dir').'/'.hash('sha256', AccountScope::key($c)).'.json';
        unlink($path);
        $this->artisan('mcp:live-order', ['--account' => $c->id, '--signal' => $signal->id, '--stage-only' => true])
            ->expectsOutputToContain('execution_recovery_checkpoint_unavailable')->assertFailed();
        $this->assertSame(0, DB::table('mcp_execution_intents')->where('mcp_connection_id', $c->id)->count());
        $this->artisan('mcp:execution-fence', ['--account' => $c->id, '--initialize' => true])->assertSuccessful();
    }

    public function test_checkpoint_advances_across_normal_claims_and_detects_rollback_of_the_file(): void
    {
        [$c, $intent, , $path] = $this->ready();
        $initial = file_get_contents($path);
        $journal = app(ExecutionJournal::class);
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->assertTrue($journal->beforeSend($c, $c->user_id, $intent->id, $token));
        $journal->withdraw($c, $c->user_id, $intent->id, $token, 'withdrawn_test_before_send');
        $next = F::stage($c, F::signal($c, 'BUY'));
        $nextToken = $journal->claim($c, $c->user_id, $next->id);
        $this->assertNotSame($token, $nextToken);
        $this->assertSame(2, json_decode(file_get_contents($path), true)['claim']['count']);
        $this->assertNull(app(ExecutionRecoveryFence::class)->reason(AccountScope::key($c)));
        file_put_contents($path, $initial);
        $this->assertFalse($journal->beforeSend($c, $c->user_id, $next->id, $nextToken));
    }

    public function test_disk_reservation_followed_by_database_rollback_blocks_the_account(): void
    {
        [$c, $intent, $gateway] = $this->ready();
        $scope = AccountScope::key($c);
        try {
            DB::transaction(function () use ($intent, $scope) {
                DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->firstOrFail();
                app(ExecutionRecoveryFence::class)->reserve($intent, $scope, (string) Str::uuid());
                throw new \RuntimeException('Synthetic commit failure after disk sync.');
            });
        } catch (\RuntimeException $failure) {
            $this->assertSame('Synthetic commit failure after disk sync.', $failure->getMessage());
        }
        $this->assertSame('execution_recovery_claim_count_differs', app(ExecutionRecoveryFence::class)->reason($scope));
        try {
            app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id);
            $this->fail('A rolled-back reservation was retried.');
        } catch (HttpException $failure) {
            $this->assertSame('execution_recovery_claim_count_differs', $failure->getMessage());
        }
        $this->assertSame([], $gateway->calls);
    }
}

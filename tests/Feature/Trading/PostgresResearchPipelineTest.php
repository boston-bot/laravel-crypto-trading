<?php

namespace Tests\Feature\Trading;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class PostgresResearchPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! filter_var(env('RUN_POSTGRES_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_POSTGRES_TESTS=1 to run PostgreSQL-specific integration tests.');
        }
        $configured = (array) config('database.connections.pgsql');
        config()->set('database.connections.pgsql.host', env('PG_TEST_HOST', $configured['host'] ?? '127.0.0.1'));
        config()->set('database.connections.pgsql.port', env('PG_TEST_PORT', $configured['port'] ?? '5432'));
        config()->set('database.connections.pgsql.database', env('PG_TEST_DATABASE', 'laravel_crypto_trading'));
        config()->set('database.connections.pgsql.username', env('PG_TEST_USERNAME', $configured['username'] ?? 'postgres'));
        config()->set('database.connections.pgsql.password', env('PG_TEST_PASSWORD', $configured['password'] ?? ''));
        DB::purge('pgsql');
    }

    public function test_raw_market_events_is_natively_partitioned(): void
    {
        $row = $this->postgres()->selectOne(<<<'SQL'
            SELECT count(*) AS total
            FROM pg_partitioned_table p
            JOIN pg_class c ON c.oid=p.partrelid
            WHERE c.relname='raw_market_events'
        SQL);

        $this->assertSame(1, (int) $row->total);
        $this->assertGreaterThanOrEqual(1, (int) $this->postgres()->scalar(<<<'SQL'
            SELECT count(*) FROM pg_inherits i
            JOIN pg_class parent ON parent.oid=i.inhparent
            WHERE parent.relname='raw_market_events'
        SQL));
    }

    public function test_laravel_postgres_session_uses_utc(): void
    {
        $timezone = (string) $this->postgres()->scalar('SHOW TIME ZONE');

        $this->assertSame('UTC', strtoupper($timezone));
    }

    public function test_skip_locked_claims_distinct_jobs_without_holding_compute_transaction(): void
    {
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        $keys = ['pg-test-'.Str::uuid(), 'pg-test-'.Str::uuid()];
        foreach ($ids as $index => $id) {
            $this->postgres()->table('engine_jobs')->insert([
                'id' => $id, 'schema_version' => '1.0', 'kind' => 'evaluate',
                'idempotency_key' => $keys[$index], 'as_of' => now(), 'payload_json' => '{}',
                'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3,
                'created_at' => now()->addSeconds($index), 'updated_at' => now(),
            ]);
        }

        $first = $this->newPdo();
        $second = $this->newPdo();
        try {
            $first->beginTransaction();
            $firstId = $first->query("SELECT id FROM engine_jobs WHERE id IN ('{$ids[0]}','{$ids[1]}') ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1")->fetchColumn();
            $second->beginTransaction();
            $secondId = $second->query("SELECT id FROM engine_jobs WHERE id IN ('{$ids[0]}','{$ids[1]}') ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1")->fetchColumn();
            $this->assertNotSame($firstId, $secondId);
            $this->assertEqualsCanonicalizing($ids, [$firstId, $secondId]);
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            $this->postgres()->table('engine_jobs')->whereIn('id', $ids)->delete();
        }
    }

    public function test_completed_strategy_version_is_database_immutable(): void
    {
        $pdo = $this->newPdo();
        $pdo->beginTransaction();
        try {
            $id = $pdo->query("INSERT INTO strategy_versions (name,version,schema_version,engine_version,status,content_hash,definition_json,created_at,updated_at) VALUES ('pg-test','v1','1.0','test','research','".hash('sha256', (string) Str::uuid())."','{}',now(),now()) RETURNING id")->fetchColumn();
            $this->expectException(\PDOException::class);
            $pdo->exec("UPDATE strategy_versions SET version='rewritten' WHERE id=".(int) $id);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_paper_session_lock_serializes_competing_buy_reservations(): void
    {
        [$accountId, $assetId, $sessionId] = $this->createPaperFixture();
        $first = $this->newPdo();
        $second = $this->newPdo();
        try {
            $first->beginTransaction();
            $first->query('SELECT id FROM paper_sessions WHERE id='.(int) $sessionId.' FOR UPDATE')->fetchColumn();

            $second->beginTransaction();
            $second->exec("SET LOCAL lock_timeout = '150ms'");
            $blocked = false;
            try {
                $second->query('SELECT id FROM paper_sessions WHERE id='.(int) $sessionId.' FOR UPDATE')->fetchColumn();
            } catch (\PDOException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, 'A competing reservation worker must block on the paper-session row.');
            $second->rollBack();

            $first->exec('UPDATE paper_sessions SET reserved_cash=60 WHERE id='.(int) $sessionId);
            $statement = $first->prepare('INSERT INTO paper_order_reservations (paper_session_id,asset_id,idempotency_key,intent_hash,side,amount,reserved_quantity,status,reserved_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,now(),now(),now())');
            $statement->execute([$sessionId, $assetId, 'pg-buy-1', hash('sha256', 'pg-buy-1'), 'buy', 60, 0, 'reserved']);
            $first->commit();

            $available = (float) $this->postgres()->table('paper_sessions')->where('id', $sessionId)->value('opening_cash')
                - (float) $this->postgres()->table('paper_sessions')->where('id', $sessionId)->value('reserved_cash');
            $this->assertSame(40.0, $available);
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            $this->deletePaperFixture($accountId, $assetId, $sessionId);
        }
    }

    public function test_unique_intent_and_order_lineage_prevent_duplicate_worker_fill(): void
    {
        [$accountId, $assetId, $sessionId] = $this->createPaperFixture();
        $pdo = $this->newPdo();
        try {
            $reservation = $pdo->prepare('INSERT INTO paper_order_reservations (paper_session_id,asset_id,idempotency_key,intent_hash,side,amount,reserved_quantity,status,reserved_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,now(),now(),now())');
            $reservation->execute([$sessionId, $assetId, 'pg-duplicate-intent', hash('sha256', 'pg-duplicate-intent'), 'buy', 10, 0, 'reserved']);

            $duplicateReservationRejected = false;
            try {
                $reservation->execute([$sessionId, $assetId, 'pg-duplicate-intent', hash('sha256', 'pg-duplicate-intent'), 'buy', 10, 0, 'reserved']);
            } catch (\PDOException) {
                $duplicateReservationRejected = true;
            }
            $this->assertTrue($duplicateReservationRejected);

            $order = $pdo->prepare('INSERT INTO broker_orders (broker_account_id,paper_session_id,asset_id,client_order_id,side,status,created_at,updated_at) VALUES (?,?,?,?,?,?,now(),now())');
            $order->execute([$accountId, $sessionId, $assetId, 'pg-fill-lineage', 'buy', 'filled']);
            $duplicateFillRejected = false;
            try {
                $order->execute([$accountId, $sessionId, $assetId, 'pg-fill-lineage', 'buy', 'filled']);
            } catch (\PDOException) {
                $duplicateFillRejected = true;
            }
            $this->assertTrue($duplicateFillRejected);
        } finally {
            $this->deletePaperFixture($accountId, $assetId, $sessionId);
        }
    }

    private function postgres(): ConnectionInterface
    {
        return DB::connection('pgsql');
    }

    private function newPdo(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{int, int, int} */
    private function createPaperFixture(): array
    {
        $suffix = str_replace('-', '', (string) Str::uuid());
        $accountId = $this->postgres()->table('broker_accounts')->insertGetId([
            'broker' => 'coinbase', 'external_account_id' => 'pg-reservation-'.$suffix,
            'currency' => 'USD', 'buying_power' => 0, 'cash_balance' => 0, 'equity' => 0,
            'status' => 'active', 'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assetId = $this->postgres()->table('assets')->insertGetId([
            'broker' => 'coinbase', 'symbol' => 'PG'.substr($suffix, 0, 12), 'asset_type' => 'crypto',
            'is_tradable' => true, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sessionId = $this->postgres()->table('paper_sessions')->insertGetId([
            'broker_account_id' => $accountId, 'funding_mode' => 'virtual', 'status' => 'active',
            'currency' => 'USD', 'opening_cash' => 100, 'reserved_cash' => 0,
            'fee_scenario' => 'test', 'slippage_scenario' => 'test', 'valuation_at' => now(),
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$accountId, $assetId, $sessionId];
    }

    private function deletePaperFixture(int $accountId, int $assetId, int $sessionId): void
    {
        $this->postgres()->table('paper_order_reservations')->where('paper_session_id', $sessionId)->delete();
        $this->postgres()->table('broker_orders')->where('paper_session_id', $sessionId)->delete();
        $this->postgres()->table('paper_sessions')->where('id', $sessionId)->delete();
        $this->postgres()->table('assets')->where('id', $assetId)->delete();
        $this->postgres()->table('broker_accounts')->where('id', $accountId)->delete();
    }
}

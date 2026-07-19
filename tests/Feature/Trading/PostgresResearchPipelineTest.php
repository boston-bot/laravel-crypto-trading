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
}

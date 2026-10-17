<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Run with phpunit.pgsql.xml against the dedicated fixture database.');
        }
        parent::setUp();
    }

    public function test_all_migrations_land_in_the_designated_schema(): void
    {
        $row = DB::selectOne('SELECT current_database() AS database, current_schema() AS schema');
        $this->assertSame('mcp_trading_test', $row->database);
        $this->assertSame('mcp_trading', $row->schema);
        $this->assertSame([], DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"));
        foreach (['migrations', 'users', 'sessions', 'jobs', 'cache', 'broker_accounts', 'trade_decisions', 'mcp_connections', 'mcp_oauth_credentials', 'mcp_oauth_attempts', 'mcp_runs', 'mcp_snapshots', 'mcp_holdings', 'mcp_policy_versions', 'mcp_thesis_versions', 'mcp_sleeve_allocations', 'mcp_flow_events', 'mcp_incidents', 'mcp_outbox', 'mcp_market_sessions', 'mcp_archive_imports', 'mcp_archive_rows', 'mcp_research_sources', 'mcp_research_events', 'mcp_research_runs', 'mcp_research_budgets', 'mcp_summaries', 'mcp_watchlist_items', 'mcp_notification_deliveries', 'mcp_component_status'] as $table) {
            $this->assertNotNull(DB::selectOne("SELECT tablename FROM pg_tables WHERE schemaname = 'mcp_trading' AND tablename = ?", [$table]));
        }
        $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }

    public function test_missing_schema_stops_migration_before_any_ddl(): void
    {
        config(['database.connections.invalid' => array_merge(config('database.connections.pgsql'), ['search_path' => 'missing'])]);
        try {
            $this->artisan('migrate', ['--database' => 'invalid', '--force' => true]);
            $this->fail('Migration accepted missing schema');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('schema', $exception->getMessage());
        }
    }
}

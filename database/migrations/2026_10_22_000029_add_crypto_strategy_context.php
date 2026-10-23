<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing records retain their equity identity. No existing cash, payload or policy is rewritten.
        foreach (['mcp_paper_portfolios', 'mcp_symbol_profiles', 'mcp_strategy_cycles', 'mcp_strategy_signals'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('mcp_connection_id')->nullable()->change();
                $t->string('asset_class', 16)->default('equity');
                $t->string('venue', 24)->default('robinhood_agentic');
            });
        }
        Schema::table('mcp_paper_portfolios', function (Blueprint $t) {
            $t->foreignId('actor_id')->nullable()->constrained('users');
        });
        Schema::create('mcp_paper_instrument_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('applied_at', 6);
            $t->unique(['paper_portfolio_id', 'version']);
            $t->unique(['paper_portfolio_id', 'request_id']);
        });
        Schema::table('mcp_symbol_profiles', function (Blueprint $t) {
            $t->foreignId('paper_portfolio_id')->nullable()->constrained('mcp_paper_portfolios');
            $t->string('symbol', 24)->change();
        });
        Schema::table('mcp_strategy_cycles', function (Blueprint $t) {
            $t->foreignId('instrument_policy_id')->nullable()->constrained('mcp_paper_instrument_policies');
        });
        Schema::table('mcp_strategy_signals', fn (Blueprint $t) => $t->string('symbol', 24)->change());
        $prefix = DB::connection()->getDriverName() === 'pgsql' ? '"mcp_trading".' : '';
        // NULL connection IDs must not defeat retry/concurrency identities for standalone paper sleeves.
        DB::statement('CREATE UNIQUE INDEX crypto_portfolio_identity ON '.$prefix.'"mcp_paper_portfolios" (actor_id, venue) WHERE asset_class = \'crypto\'');
        DB::statement('CREATE UNIQUE INDEX crypto_profile_request ON '.$prefix.'"mcp_symbol_profiles" (paper_portfolio_id, request_id) WHERE asset_class = \'crypto\'');
        DB::statement('CREATE UNIQUE INDEX crypto_cycle_identity ON '.$prefix.'"mcp_strategy_cycles" (paper_portfolio_id, instrument_policy_id, rule_version, cycle_at) WHERE asset_class = \'crypto\'');
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['mcp_paper_portfolios', 'mcp_symbol_profiles', 'mcp_strategy_cycles', 'mcp_strategy_signals'] as $table) {
                DB::statement('ALTER TABLE "mcp_trading"."'.$table.'" ADD CONSTRAINT '.$table.'_instrument_context CHECK ((asset_class = \'equity\' AND venue = \'robinhood_agentic\' AND mcp_connection_id IS NOT NULL) OR (asset_class = \'crypto\' AND venue = \'coinbase\' AND mcp_connection_id IS NULL))');
            }
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_portfolios" ADD CONSTRAINT crypto_portfolio_actor CHECK (asset_class <> \'crypto\' OR actor_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        // Restoring NOT NULL would require deleting legitimate crypto records. Never automate that rollback.
        throw new RuntimeException('Forward-only context migration; restore a verified backup or use a reviewed forward migration.');
    }
};

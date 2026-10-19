<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_strategy_cycles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->string('scope', 64);
            $t->unsignedInteger('auth_generation');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('rule_version', 32);
            $t->timestampTz('cycle_at', 6);
            $t->timestampTz('cutoff', 6);
            $t->timestampTz('recorded_at', 6);
            $t->text('payload');
            $t->unique(['mcp_connection_id', 'scope', 'auth_generation', 'rule_version', 'cycle_at'], 'strategy_cycle_identity');
        });
        Schema::create('mcp_strategy_signals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('cycle_id')->constrained('mcp_strategy_cycles');
            $t->foreignId('profile_id')->nullable()->constrained('mcp_symbol_profiles');
            $t->foreignId('watchlist_revision_id')->nullable()->constrained('mcp_strategy_watchlist_revisions');
            $t->foreignId('market_input_id')->nullable()->constrained('mcp_paper_market_inputs');
            $t->string('symbol', 16);
            $t->string('status', 16);
            $t->string('side', 4)->nullable();
            $t->string('reason', 96);
            $t->string('rule_version', 32);
            $t->string('parameters_version', 48);
            $t->string('input_fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('cutoff', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['cycle_id', 'symbol'], 'strategy_signal_identity');
            $t->index(['paper_portfolio_id', 'symbol', 'id'], 'strategy_signal_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_signals" ADD CONSTRAINT strategy_signal_states CHECK ((status = \'signal\' AND side IS NOT NULL AND side IN (\'BUY\',\'SELL\')) OR (status IN (\'wait\',\'unavailable\') AND side IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_strategy_signals');
        Schema::dropIfExists('mcp_strategy_cycles');
    }
};

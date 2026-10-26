<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_strategy_cycles', function (Blueprint $t) {
            $t->string('position_source', 8)->default('paper');
            $t->dropUnique('strategy_cycle_identity');
            $t->unique(['mcp_connection_id', 'scope', 'auth_generation', 'rule_version', 'cycle_at', 'position_source'], 'strategy_cycle_identity');
            $t->unique(['id', 'position_source'], 'strategy_cycle_source');
        });
        Schema::table('mcp_strategy_signals', function (Blueprint $t) {
            $t->string('position_source', 8)->default('paper');
            $t->foreign(['cycle_id', 'position_source'], 'strategy_signal_cycle_source')->references(['id', 'position_source'])->on('mcp_strategy_cycles');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['cycles', 'signals'] as $table) {
                DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_'.$table.'" ADD CONSTRAINT strategy_'.$table.'_source CHECK (position_source = \'paper\' OR (position_source = \'live\' AND asset_class = \'equity\' AND venue = \'robinhood_agentic\'))');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('mcp_strategy_cycles')->where('position_source', 'live')->exists()) {
            throw new RuntimeException('Live signal evidence exists; retain its source identity.');
        }
        Schema::table('mcp_strategy_signals', function (Blueprint $t) {
            $t->dropForeign('strategy_signal_cycle_source');
            $t->dropColumn('position_source');
        });
        Schema::table('mcp_strategy_cycles', function (Blueprint $t) {
            $t->dropUnique('strategy_cycle_identity');
            $t->dropUnique('strategy_cycle_source');
            $t->dropColumn('position_source');
            $t->unique(['mcp_connection_id', 'scope', 'auth_generation', 'rule_version', 'cycle_at'], 'strategy_cycle_identity');
        });
    }
};

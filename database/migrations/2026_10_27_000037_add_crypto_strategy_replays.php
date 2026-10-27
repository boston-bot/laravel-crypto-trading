<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Crypto replays belong to a standalone crypto sleeve. Existing rows keep their equity identity.
        Schema::table('mcp_strategy_replays', function (Blueprint $t) {
            $t->unsignedBigInteger('mcp_connection_id')->nullable()->change();
            $t->foreignId('paper_portfolio_id')->nullable()->constrained('mcp_paper_portfolios');
            $t->string('asset_class', 16)->default('equity');
            $t->string('venue', 24)->default('robinhood_agentic');
        });
        $prefix = DB::connection()->getDriverName() === 'pgsql' ? '"mcp_trading".' : '';
        DB::statement('CREATE UNIQUE INDEX crypto_replay_request ON '.$prefix.'"mcp_strategy_replays" (paper_portfolio_id, request_id) WHERE asset_class = \'crypto\'');
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Replay history backfill reads are recorded like collection reads, with their own trigger.
            DB::statement('ALTER TABLE "mcp_trading"."mcp_crypto_market_reads" DROP CONSTRAINT crypto_market_read_states');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_crypto_market_reads" ADD CONSTRAINT crypto_market_read_states CHECK (venue = \'coinbase\' AND trigger IN (\'scheduled\',\'manual\',\'backfill\') AND status IN (\'running\',\'complete\',\'partial\',\'failed\'))');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_replays" ADD CONSTRAINT strategy_replay_instrument_context CHECK ((asset_class = \'equity\' AND venue = \'robinhood_agentic\' AND mcp_connection_id IS NOT NULL) OR (asset_class = \'crypto\' AND venue = \'coinbase\' AND mcp_connection_id IS NULL AND paper_portfolio_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        // Restoring NOT NULL would require deleting legitimate crypto replay history. Never automate that rollback.
        throw new RuntimeException('Forward-only replay context migration; restore a verified backup or use a reviewed forward migration.');
    }
};

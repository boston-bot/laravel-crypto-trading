<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_sim_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('policy_application_id')->constrained('mcp_paper_policy_applications');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_id');
            $t->string('intent_hash', 64);
            $t->text('intent');
            $t->text('assumptions');
            $t->unsignedInteger('auth_generation');
            $t->string('symbol', 16);
            $t->string('sleeve', 16);
            $t->string('side', 4);
            $t->string('type', 6);
            $t->string('status', 16);
            $t->decimal('quantity', 38, 18);
            $t->decimal('filled_quantity', 38, 18)->default(0);
            $t->decimal('price_bound', 38, 18);
            $t->decimal('reserved_cash', 38, 18)->default(0);
            $t->timestampTz('accepted_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('last_source_at')->nullable();
            $t->unique(['paper_portfolio_id', 'request_id']);
        });
        Schema::create('mcp_paper_sim_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->string('symbol', 16);
            $t->string('sleeve', 16);
            $t->decimal('quantity', 38, 18)->default(0);
            $t->string('blocked_reason', 64)->nullable();
            $t->decimal('reserved_quantity', 38, 18)->default(0);
            $t->decimal('cost_basis', 38, 18)->default(0);
            $t->decimal('realized_pnl', 38, 18)->default(0);
            $t->unique(['paper_portfolio_id', 'symbol', 'sleeve']);
        });
        Schema::create('mcp_paper_sim_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->string('source_key', 100);
            $t->string('sample_key', 64);
            $t->string('fingerprint', 64);
            $t->text('snapshot');
            $t->timestampTz('source_at')->nullable();
            $t->timestampTz('retrieved_at');
            $t->timestampTz('recorded_at');
            $t->unique(['paper_portfolio_id', 'source_key']);
            $t->unique(['paper_portfolio_id', 'sample_key']);
        });
        Schema::create('mcp_paper_sim_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sim_order_id')->constrained('mcp_paper_sim_orders');
            $t->foreignId('observation_id')->nullable()->constrained('mcp_paper_sim_observations');
            $t->string('event_key', 120);
            $t->string('kind', 24);
            $t->string('reason', 64)->nullable();
            $t->decimal('quantity', 38, 18)->default(0);
            $t->decimal('price', 38, 18)->nullable();
            $t->decimal('fee', 38, 18)->default(0);
            $t->decimal('cash_delta', 38, 18)->default(0);
            $t->unsignedBigInteger('ledger_version');
            $t->text('evidence');
            $t->timestampTz('recorded_at');
            $t->unique(['sim_order_id', 'event_key']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_sim_orders" ADD CONSTRAINT sim_order_bounds CHECK (quantity > 0 AND filled_quantity >= 0 AND filled_quantity <= quantity AND price_bound > 0 AND reserved_cash >= 0 AND side IN (\'BUY\', \'SELL\') AND type IN (\'market\', \'limit\') AND status IN (\'open\', \'partial\', \'filled\', \'cancelled\', \'expired\', \'rejected\'))');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_sim_positions" ADD CONSTRAINT sim_position_bounds CHECK (quantity >= reserved_quantity AND reserved_quantity >= 0 AND cost_basis >= 0)');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_sim_events" ADD CONSTRAINT sim_event_bounds CHECK (quantity >= 0 AND fee >= 0 AND (price IS NULL OR price > 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_sim_events');
        Schema::dropIfExists('mcp_paper_sim_observations');
        Schema::dropIfExists('mcp_paper_sim_positions');
        Schema::dropIfExists('mcp_paper_sim_orders');
    }
};

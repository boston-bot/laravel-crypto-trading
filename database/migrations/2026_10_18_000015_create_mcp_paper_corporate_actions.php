<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_action_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('market_input_id')->constrained('mcp_paper_market_inputs');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('provider_event_id', 128);
            $t->string('fingerprint', 64);
            $t->string('symbol', 16);
            $t->string('event_type', 40);
            $t->string('phase', 16);
            $t->string('status', 16);
            $t->string('reason', 64)->nullable();
            $t->timestampTz('effective_at')->nullable();
            $t->unsignedBigInteger('ledger_version');
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['paper_portfolio_id', 'provider_event_id', 'fingerprint', 'phase'], 'paper_action_idempotency');
            $t->index(['paper_portfolio_id', 'symbol', 'effective_at'], 'paper_action_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_action_records" ADD CONSTRAINT paper_action_states CHECK (phase IN (\'observation\',\'entitlement\',\'application\',\'block\') AND status IN (\'observed\',\'captured\',\'applied\',\'no_effect\',\'blocked\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_action_records');
    }
};

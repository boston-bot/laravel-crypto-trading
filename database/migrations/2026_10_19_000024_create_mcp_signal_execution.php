<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_signal_execution_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('policy_application_id')->constrained('mcp_paper_policy_applications');
            $t->foreignId('execution_version_id')->constrained('mcp_paper_execution_versions');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->text('payload');
            $t->timestampTz('applied_at', 6);
            $t->unique(['paper_portfolio_id', 'version'], 'signal_execution_version');
            $t->unique(['paper_portfolio_id', 'request_id'], 'signal_execution_request');
        });
        Schema::create('mcp_signal_order_handoffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('signal_id')->unique()->constrained('mcp_strategy_signals');
            $t->foreignId('signal_policy_id')->nullable()->constrained('mcp_signal_execution_policies');
            $t->foreignId('sim_order_id')->nullable()->constrained('mcp_paper_sim_orders');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 24);
            $t->string('reason', 96)->nullable();
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
        });
        // Widen timestamp precision so an opening signal cannot appear later than its accepted paper intent.
        Schema::table('mcp_paper_sim_orders', function (Blueprint $t) {
            $t->timestampTz('accepted_at', 6)->change();
            $t->timestampTz('expires_at', 6)->change();
            $t->timestampTz('last_source_at', 6)->nullable()->change();
        });
        Schema::table('mcp_paper_sim_events', fn (Blueprint $t) => $t->timestampTz('recorded_at', 6)->change());
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_signal_order_handoffs" ADD CONSTRAINT signal_handoff_states CHECK ((status = \'order_created\' AND sim_order_id IS NOT NULL) OR status IN (\'unavailable\', \'rejected\', \'no_order\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_signal_order_handoffs');
        Schema::dropIfExists('mcp_signal_execution_policies');
    }
};

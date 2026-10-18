<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_execution_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('policy_application_id')->constrained('mcp_paper_policy_applications');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->string('kind', 16);
            $t->unsignedBigInteger('draft_id')->nullable();
            $t->text('payload');
            $t->string('reason', 500);
            $t->timestampTz('created_at');
            $t->unique(['paper_portfolio_id', 'version']);
            $t->unique(['paper_portfolio_id', 'request_id']);
        });
        Schema::create('mcp_paper_market_inputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->string('symbol', 16);
            $t->string('origin', 32);
            $t->string('status', 32);
            $t->string('reason', 64)->nullable();
            $t->text('payload');
            $t->timestampTz('source_at', 6)->nullable();
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id']);
            $t->index(['mcp_connection_id', 'scope', 'symbol', 'recorded_at']);
        });
        Schema::table('mcp_paper_decision_runs', function (Blueprint $t) {
            $t->foreignId('execution_version_id')->nullable()->constrained('mcp_paper_execution_versions');
        });
        Schema::table('mcp_paper_sim_orders', function (Blueprint $t) {
            $t->foreignId('execution_version_id')->nullable()->constrained('mcp_paper_execution_versions');
        });
        Schema::create('mcp_paper_order_handoffs', function (Blueprint $t) {
            $t->id();
            $t->uuid('decision_run_id');
            $t->foreign('decision_run_id')->references('id')->on('mcp_paper_decision_runs');
            $t->foreignId('execution_version_id')->nullable()->constrained('mcp_paper_execution_versions');
            $t->foreignId('sim_order_id')->nullable()->constrained('mcp_paper_sim_orders');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('symbol', 16);
            $t->string('status', 24);
            $t->string('reason', 64)->nullable();
            $t->text('evidence');
            $t->timestampTz('created_at');
            $t->unique(['decision_run_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_order_handoffs');
        Schema::table('mcp_paper_sim_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('execution_version_id'));
        Schema::table('mcp_paper_decision_runs', fn (Blueprint $t) => $t->dropConstrainedForeignId('execution_version_id'));
        Schema::dropIfExists('mcp_paper_market_inputs');
        Schema::dropIfExists('mcp_paper_execution_versions');
    }
};

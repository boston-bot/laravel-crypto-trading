<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_decision_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('policy_application_id')->constrained('mcp_paper_policy_applications');
            $t->unsignedBigInteger('ledger_version');
            $t->unsignedInteger('auth_generation');
            $t->string('dedup_key', 128);
            $t->string('status', 32);
            $t->string('reason', 64)->nullable();
            $t->foreignId('active_portfolio_id')->nullable()->constrained('mcp_paper_portfolios')->unique();
            $t->uuid('claim')->nullable();
            $t->string('model', 128)->nullable();
            $t->string('prompt_version', 32)->nullable();
            $t->string('context_hash', 64)->nullable();
            $t->text('request_snapshot')->nullable();
            $t->text('result')->nullable();
            $t->string('budget_period', 7)->nullable();
            $t->text('price_basis')->nullable();
            $t->decimal('reserved_cost', 38, 18)->default(0);
            $t->decimal('actual_cost', 38, 18)->default(0);
            $t->string('cost_basis', 32)->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->timestampTz('scheduled_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('call_started_at')->nullable();
            $t->timestampTz('response_received_at')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['paper_portfolio_id', 'dedup_key']);
            $t->index(['mcp_connection_id', 'scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_decision_runs');
    }
};

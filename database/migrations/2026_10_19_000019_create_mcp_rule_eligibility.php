<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_eligibility_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('execution_version_id')->constrained('mcp_paper_execution_versions');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->text('payload');
            $t->timestampTz('applied_at', 6);
            $t->unique(['paper_portfolio_id', 'version'], 'eligibility_policy_version');
            $t->unique(['paper_portfolio_id', 'request_id'], 'eligibility_policy_request');
        });
        Schema::create('mcp_eligibility_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->unsignedInteger('auth_generation');
            $t->foreignId('policy_id')->constrained('mcp_eligibility_policies');
            $t->foreignId('execution_version_id')->constrained('mcp_paper_execution_versions');
            $t->foreignId('watchlist_revision_id')->nullable()->constrained('mcp_strategy_watchlist_revisions');
            $t->foreignId('market_input_id')->nullable()->constrained('mcp_paper_market_inputs');
            $t->string('symbol', 16);
            $t->uuid('request_id');
            $t->string('rule_version', 64);
            $t->string('input_fingerprint', 64);
            $t->string('status', 16);
            $t->text('payload');
            $t->timestampTz('cutoff', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id', 'symbol'], 'eligibility_assessment_request');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_eligibility_policies" ADD CONSTRAINT eligibility_positive_version CHECK (version > 0)');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_eligibility_assessments" ADD CONSTRAINT eligibility_assessment_status CHECK (status IN (\'eligible\',\'ineligible\',\'unavailable\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_eligibility_assessments');
        Schema::dropIfExists('mcp_eligibility_policies');
    }
};

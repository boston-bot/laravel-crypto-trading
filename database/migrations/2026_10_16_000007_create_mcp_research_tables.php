<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_research_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('symbol', 16);
            $t->string('provider', 64);
            $t->string('kind', 32);
            $t->string('content_hash', 64);
            $t->text('payload');
            $t->timestampTz('source_as_of');
            $t->timestampTz('retrieved_at');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['mcp_connection_id', 'scope', 'content_hash']);
            $t->index(['mcp_connection_id', 'scope', 'symbol', 'kind']);
        });
        Schema::create('mcp_research_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('event_key', 64);
            $t->string('subject', 16);
            $t->string('kind', 32);
            $t->string('priority', 16);
            $t->string('status', 32)->default('pending');
            $t->string('status_reason', 64)->nullable();
            $t->foreignId('source_id')->nullable()->constrained('mcp_research_sources');
            $t->uuid('run_id')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->timestampTz('dispatched_at')->nullable();
            $t->unique(['mcp_connection_id', 'scope', 'event_key']);
            $t->index(['mcp_connection_id', 'scope', 'status']);
        });
        Schema::create('mcp_research_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->foreignId('event_id')->constrained('mcp_research_events');
            $t->string('subject', 16);
            $t->unsignedBigInteger('thesis_version_id')->nullable();
            $t->string('cache_key', 64);
            // Set only while running. NULLs are distinct, so this serializes one active run per subject.
            $t->string('active_subject', 16)->nullable();
            $t->string('status', 32);
            $t->string('error_code', 64)->nullable();
            $t->string('provider', 32);
            $t->string('model', 128);
            $t->string('prompt_version', 32);
            $t->string('prompt_hash', 64);
            $t->string('context_hash', 64)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->string('budget_period', 7);
            $t->decimal('reserved_cost', 38, 18)->default(0);
            $t->decimal('actual_cost', 38, 18)->default(0);
            $t->string('cost_basis', 32)->nullable();
            $t->text('result')->nullable();
            $t->uuid('claim')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();
            $t->unique(['mcp_connection_id', 'scope', 'active_subject']);
            $t->index(['mcp_connection_id', 'scope', 'cache_key', 'status']);
        });
        Schema::create('mcp_research_budgets', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->unique();
            $t->decimal('envelope', 38, 18);
            $t->decimal('fixed_costs', 38, 18);
            $t->decimal('reserved', 38, 18)->default(0);
            $t->decimal('actual', 38, 18)->default(0);
            $t->timestampTz('reduced_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('mcp_summaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('kind', 16);
            $t->string('period', 16);
            $t->text('payload');
            $t->timestampTz('generated_at');
            $t->unique(['mcp_connection_id', 'scope', 'kind', 'period']);
        });
        Schema::create('mcp_watchlist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('symbol', 16);
            $t->string('active_symbol', 16)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('added_by')->constrained('users');
            $t->timestampTz('added_at');
            $t->foreignId('removed_by')->nullable()->constrained('users');
            $t->timestampTz('removed_at')->nullable();
            $t->unique(['mcp_connection_id', 'scope', 'active_symbol']);
        });
    }

    public function down(): void
    {
        foreach (['mcp_watchlist_items', 'mcp_summaries', 'mcp_research_budgets', 'mcp_research_runs', 'mcp_research_events', 'mcp_research_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('dedup_key', 128);
            $t->string('trigger', 32);
            $t->string('status', 32)->default('queued');
            $t->unsignedInteger('auth_generation');
            $t->uuid('claim')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('scheduled_at');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->string('step', 32)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->unsignedBigInteger('policy_version_id')->nullable();
            $t->timestampsTz();
            $t->unique(['mcp_connection_id', 'scope', 'dedup_key']);
        });
        Schema::create('mcp_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->foreignUuid('run_id')->unique()->constrained('mcp_runs');
            $t->string('status', 32)->default('staged');
            $t->text('payload')->nullable();
            $t->text('observations')->nullable();
            $t->timestampTz('retrieved_at')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->unsignedBigInteger('publication_version')->nullable();
            $t->unique(['mcp_connection_id', 'scope', 'publication_version']);
            $t->timestampsTz();
            $t->index(['mcp_connection_id', 'scope', 'status']);
        });
        Schema::create('mcp_holdings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('snapshot_id')->constrained('mcp_snapshots');
            $t->string('instrument_key', 128);
            $t->string('symbol', 64)->nullable();
            $t->decimal('quantity', 38, 18);
            $t->decimal('market_value', 38, 18)->nullable();
            $t->text('attribution');
            $t->unique(['snapshot_id', 'instrument_key']);
        });
        foreach (['mcp_policy_versions', 'mcp_thesis_versions', 'mcp_sleeve_allocations', 'mcp_flow_events'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('mcp_connection_id')->constrained();
                $t->string('scope', 64);
                $t->string('record_key', 128);
                $t->foreignId('actor_id')->constrained('users');
                $t->text('payload');
                $t->timestampTz('effective_at');
                $t->timestampTz('created_at')->useCurrent();
                $t->unique(['mcp_connection_id', 'scope', 'record_key']);
            });
        }
        Schema::create('mcp_incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('incident_key', 128);
            $t->string('severity', 16);
            $t->string('state', 16)->default('open');
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->foreignId('acknowledged_by')->nullable()->constrained('users');
            $t->timestampTz('acknowledged_at')->nullable();
            $t->unique(['mcp_connection_id', 'scope', 'incident_key']);
        });
        Schema::create('mcp_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event_key', 191)->unique();
            $t->string('kind', 32);
            $t->text('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('mcp_market_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('exchange', 16);
            $t->date('session_date');
            $t->string('status', 16);
            $t->timestampTz('opens_at')->nullable();
            $t->timestampTz('closes_at')->nullable();
            $t->string('source', 191);
            $t->timestampTz('retrieved_at');
            $t->unique(['exchange', 'session_date']);
        });
        Schema::create('mcp_archive_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('source_hash', 64);
            $t->foreignId('actor_id')->constrained('users');
            $t->text('report');
            $t->timestampTz('committed_at');
            $t->unique(['mcp_connection_id', 'scope', 'source_hash']);
        });
        Schema::create('mcp_archive_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_id')->constrained('mcp_archive_imports');
            $t->unsignedInteger('source_row');
            $t->string('source_ref', 128)->nullable();
            $t->string('status', 32);
            $t->string('reason', 64)->nullable();
            $t->text('payload');
            $t->unique(['import_id', 'source_row']);
        });
    }

    public function down(): void
    {
        foreach (['mcp_archive_rows', 'mcp_archive_imports', 'mcp_market_sessions', 'mcp_outbox', 'mcp_incidents', 'mcp_flow_events', 'mcp_sleeve_allocations', 'mcp_thesis_versions', 'mcp_policy_versions', 'mcp_holdings', 'mcp_snapshots', 'mcp_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

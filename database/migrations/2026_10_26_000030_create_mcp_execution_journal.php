<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_execution_accounts', function (Blueprint $t) {
            $t->id();
            // Scope is shared by every connection bound to the same broker account.
            $t->string('scope', 64)->unique();
        });
        Schema::create('mcp_execution_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->foreignId('mcp_connection_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->foreignId('signal_id')->constrained('mcp_strategy_signals');
            $t->unsignedInteger('auth_generation');
            $t->uuid('idempotency_key')->unique();
            $t->string('fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
            $t->timestampTz('expires_at', 6);
            $t->unique(['execution_account_id', 'signal_id'], 'execution_signal_once');
        });
        Schema::create('mcp_execution_dispatches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->unique()->constrained('mcp_execution_intents');
            $t->string('status', 16)->default('staged');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('claim_token')->nullable()->unique();
            $t->timestampTz('claimed_at', 6)->nullable();
            $t->timestampTz('updated_at', 6);
        });
        Schema::create('mcp_execution_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('mcp_execution_intents');
            $t->unsignedInteger('version');
            $t->string('status', 16);
            $t->string('reason', 96);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['intent_id', 'version']);
        });
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_intents" ADD CONSTRAINT execution_intent_expiry CHECK (expires_at > recorded_at AND auth_generation >= 0)');
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_dispatches\" ADD CONSTRAINT execution_dispatch_state CHECK (
            (status = 'staged' AND version = 1 AND claim_token IS NULL AND claimed_at IS NULL) OR
            (status IN ('cancelled','expired') AND version = 2 AND claim_token IS NULL AND claimed_at IS NULL) OR
            (status = 'submitting' AND version = 2 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
            (status = 'unknown' AND version = 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL))");
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_events\" ADD CONSTRAINT execution_event_state CHECK (
            (status = 'staged' AND version = 1) OR (status IN ('submitting','cancelled','expired') AND version = 2) OR (status = 'unknown' AND version = 3))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION "mcp_trading".execution_history_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Execution history is immutable';
END;
$$;
CREATE TRIGGER execution_intent_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_intents"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_event_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_events"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_dispatch_undeletable BEFORE DELETE ON "mcp_trading"."mcp_execution_dispatches"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE OR REPLACE FUNCTION "mcp_trading".execution_dispatch_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.intent_id <> OLD.intent_id OR NEW.id <> OLD.id OR NEW.version <> OLD.version + 1 OR
       NOT ((OLD.status = 'staged' AND NEW.status IN ('submitting','cancelled','expired')) OR
            (OLD.status = 'submitting' AND NEW.status = 'unknown' AND NEW.claim_token = OLD.claim_token AND NEW.claimed_at = OLD.claimed_at)) THEN
        RAISE EXCEPTION 'Invalid execution transition';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER execution_dispatch_transition BEFORE UPDATE ON "mcp_trading"."mcp_execution_dispatches"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_dispatch_transition();
SQL);
    }

    public function down(): void
    {
        foreach (['events', 'dispatches', 'intents', 'accounts'] as $table) {
            Schema::dropIfExists('mcp_execution_'.$table);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS "mcp_trading".execution_history_immutable()');
            DB::statement('DROP FUNCTION IF EXISTS "mcp_trading".execution_dispatch_transition()');
        }
    }
};

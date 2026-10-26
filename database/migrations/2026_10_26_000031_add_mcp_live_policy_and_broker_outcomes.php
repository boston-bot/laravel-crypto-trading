<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive: live policy versions, owner controls and broker-confirmed outcomes. No backfill; existing journal rows keep their states. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_execution_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->unsignedInteger('version');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_id')->unique();
            $t->string('fingerprint', 64)->unique();
            $t->text('payload');
            $t->timestampTz('applied_at', 6);
            $t->unique(['execution_account_id', 'version']);
        });
        Schema::create('mcp_execution_controls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->unsignedInteger('version');
            $t->boolean('buy_paused');
            $t->boolean('emergency_disabled');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_id')->unique();
            $t->string('reason', 200);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['execution_account_id', 'version']);
        });
        Schema::create('mcp_execution_broker_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->unique()->constrained('mcp_execution_intents');
            $t->string('broker_id_hash', 64)->unique();
            $t->text('broker_order_id');
            $t->string('source', 24);
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('external_order_id')->nullable()->unique()->constrained('mcp_external_orders');
            $t->uuid('request_id')->nullable()->unique();
            $t->timestampTz('recorded_at', 6);
        });
        Schema::create('mcp_execution_outcomes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('mcp_execution_intents');
            $t->foreignId('broker_order_read_id')->constrained('mcp_broker_order_reads');
            $t->string('broker_state', 24);
            $t->string('status', 16);
            $t->decimal('filled_quantity', 38, 18);
            $t->decimal('average_price', 38, 18)->nullable();
            $t->decimal('fees', 38, 18)->nullable();
            $t->timestampTz('source_as_of', 6)->nullable();
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['intent_id', 'broker_order_read_id']);
        });
        Schema::create('mcp_execution_fills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('mcp_execution_intents');
            $t->foreignId('outcome_id')->constrained('mcp_execution_outcomes');
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->string('execution_id_hash', 64)->unique();
            $t->string('symbol', 16);
            $t->string('side', 4);
            $t->decimal('quantity', 38, 18);
            $t->decimal('price', 38, 18);
            $t->decimal('fees', 38, 18);
            $t->timestampTz('executed_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->index(['execution_account_id', 'symbol']);
        });
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_policies" ADD CONSTRAINT execution_policy_version CHECK (version >= 1)');
        DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_controls" ADD CONSTRAINT execution_control_version CHECK (version >= 1)');
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_broker_orders\" ADD CONSTRAINT execution_broker_order_source CHECK (
            (source = 'submission_response' AND actor_id IS NULL AND external_order_id IS NULL AND request_id IS NULL) OR
            (source = 'owner_link' AND actor_id IS NOT NULL AND external_order_id IS NOT NULL AND request_id IS NOT NULL))");
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_outcomes\" ADD CONSTRAINT execution_outcome_bounds CHECK (
            filled_quantity >= 0 AND (average_price IS NULL OR average_price > 0) AND (fees IS NULL OR fees >= 0) AND
            status IN ('accepted','partially_filled','filled','broker_cancelled','rejected'))");
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_fills\" ADD CONSTRAINT execution_fill_bounds CHECK (
            quantity > 0 AND price > 0 AND fees >= 0 AND side IN ('BUY','SELL'))");
        DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_dispatches" DROP CONSTRAINT execution_dispatch_state');
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_dispatches\" ADD CONSTRAINT execution_dispatch_state CHECK (
            (status = 'staged' AND version = 1 AND claim_token IS NULL AND claimed_at IS NULL) OR
            (status IN ('cancelled','expired') AND version = 2 AND claim_token IS NULL AND claimed_at IS NULL) OR
            (status = 'submitting' AND version = 2 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
            (status IN ('unknown','withdrawn') AND version = 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
            (status IN ('accepted','absent','partially_filled','filled','broker_cancelled','rejected') AND version >= 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL))");
        DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_events" DROP CONSTRAINT execution_event_state');
        DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_events\" ADD CONSTRAINT execution_event_state CHECK (
            (status = 'staged' AND version = 1) OR (status IN ('submitting','cancelled','expired') AND version = 2) OR
            (status IN ('unknown','withdrawn') AND version = 3) OR
            (status IN ('accepted','absent','partially_filled','filled','broker_cancelled','rejected') AND version >= 3))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION "mcp_trading".execution_dispatch_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.intent_id <> OLD.intent_id OR NEW.id <> OLD.id OR NEW.version <> OLD.version + 1 OR
       (OLD.status <> 'staged' AND (NEW.claim_token IS DISTINCT FROM OLD.claim_token OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at)) OR
       NOT ((OLD.status = 'staged' AND NEW.status IN ('submitting','cancelled','expired')) OR
            (OLD.status = 'submitting' AND NEW.status IN ('unknown','accepted','withdrawn')) OR
            (OLD.status = 'unknown' AND NEW.status IN ('accepted','absent')) OR
            (OLD.status = 'accepted' AND NEW.status IN ('partially_filled','filled','broker_cancelled','rejected')) OR
            (OLD.status = 'partially_filled' AND NEW.status IN ('filled','broker_cancelled'))) THEN
        RAISE EXCEPTION 'Invalid execution transition';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER execution_policy_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_policies"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_control_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_controls"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_broker_order_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_broker_orders"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_outcome_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_outcomes"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
CREATE TRIGGER execution_fill_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_fills"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
SQL);
    }

    public function down(): void
    {
        foreach (['fills', 'outcomes', 'broker_orders', 'controls', 'policies'] as $table) {
            Schema::dropIfExists('mcp_execution_'.$table);
        }
        // Restoring the narrower 000030 lifecycle fails if broker outcome states exist; that refusal is intended.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_dispatches" DROP CONSTRAINT execution_dispatch_state');
            DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_dispatches\" ADD CONSTRAINT execution_dispatch_state CHECK (
                (status = 'staged' AND version = 1 AND claim_token IS NULL AND claimed_at IS NULL) OR
                (status IN ('cancelled','expired') AND version = 2 AND claim_token IS NULL AND claimed_at IS NULL) OR
                (status = 'submitting' AND version = 2 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
                (status = 'unknown' AND version = 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL))");
            DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_events" DROP CONSTRAINT execution_event_state');
            DB::statement("ALTER TABLE \"mcp_trading\".\"mcp_execution_events\" ADD CONSTRAINT execution_event_state CHECK (
                (status = 'staged' AND version = 1) OR (status IN ('submitting','cancelled','expired') AND version = 2) OR (status = 'unknown' AND version = 3))");
            DB::unprepared(<<<'SQL'
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
SQL);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive: broker approval requests and ref_id-proven order bindings. No backfill; existing journal rows keep their states. */
return new class extends Migration
{
    private const DISPATCH = "
        (status = 'staged' AND version = 1 AND claim_token IS NULL AND claimed_at IS NULL) OR
        (status IN ('cancelled','expired') AND version = 2 AND claim_token IS NULL AND claimed_at IS NULL) OR
        (status = 'submitting' AND version = 2 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
        (status IN ('unknown','withdrawn',%s) AND version = 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL) OR
        (status IN ('accepted','absent','partially_filled','filled','broker_cancelled','rejected') AND version >= 3 AND claim_token IS NOT NULL AND claimed_at IS NOT NULL)";

    private const EVENT = "
        (status = 'staged' AND version = 1) OR (status IN ('submitting','cancelled','expired') AND version = 2) OR
        (status IN ('unknown','withdrawn',%s) AND version = 3) OR
        (status IN ('accepted','absent','partially_filled','filled','broker_cancelled','rejected') AND version >= 3)";

    private const SOURCE = "
        (source IN ('submission_response'%s) AND actor_id IS NULL AND external_order_id IS NULL AND request_id IS NULL) OR
        (source = 'owner_link' AND actor_id IS NOT NULL AND external_order_id IS NOT NULL AND request_id IS NOT NULL)";

    private const TRANSITION = <<<'SQL'
CREATE OR REPLACE FUNCTION "mcp_trading".execution_dispatch_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.intent_id <> OLD.intent_id OR NEW.id <> OLD.id OR NEW.version <> OLD.version + 1 OR
       (OLD.status <> 'staged' AND (NEW.claim_token IS DISTINCT FROM OLD.claim_token OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at)) OR
       NOT ((OLD.status = 'staged' AND NEW.status IN ('submitting','cancelled','expired')) OR
            (OLD.status = 'submitting' AND NEW.status IN ('unknown','accepted','withdrawn'%s)) OR
            (OLD.status IN ('unknown'%s) AND NEW.status IN ('accepted','absent')) OR
            (OLD.status = 'accepted' AND NEW.status IN ('partially_filled','filled','broker_cancelled','rejected')) OR
            (OLD.status = 'partially_filled' AND NEW.status IN ('filled','broker_cancelled'))) THEN
        RAISE EXCEPTION 'Invalid execution transition';
    END IF;
    RETURN NEW;
END;
$$;
SQL;

    public function up(): void
    {
        Schema::create('mcp_execution_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->unique()->constrained('mcp_execution_intents');
            $t->string('approval_id_hash', 64)->unique();
            $t->text('approval_id');
            // Broker text exactly as returned; no code branches on approval state spellings until they are observed.
            $t->string('initial_state', 64);
            $t->boolean('is_edited');
            $t->timestampTz('expires_at', 6);
            $t->timestampTz('recorded_at', 6);
        });
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $this->lifecycle(true);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER execution_approval_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_approvals"
FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_execution_approvals');
        // Restoring the 000031 lifecycle fails if approval states or ref_id bindings exist; that refusal is intended.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->lifecycle(false);
        }
    }

    private function lifecycle(bool $approvals): void
    {
        foreach ([['mcp_execution_dispatches', 'execution_dispatch_state', self::DISPATCH, "'approval_pending'"],
            ['mcp_execution_events', 'execution_event_state', self::EVENT, "'approval_pending'"],
            ['mcp_execution_broker_orders', 'execution_broker_order_source', self::SOURCE, ",'ref_id_match'"]] as [$table, $name, $check, $extra]) {
            DB::statement('ALTER TABLE "mcp_trading"."'.$table.'" DROP CONSTRAINT '.$name);
            $value = $approvals ? $extra : ($name === 'execution_broker_order_source' ? '' : "'withdrawn'");
            DB::statement('ALTER TABLE "mcp_trading"."'.$table.'" ADD CONSTRAINT '.$name.' CHECK ('.sprintf($check, $value).')');
        }
        DB::unprepared($approvals ? sprintf(self::TRANSITION, ",'approval_pending'", ",'approval_pending'") : sprintf(self::TRANSITION, '', ''));
    }
};

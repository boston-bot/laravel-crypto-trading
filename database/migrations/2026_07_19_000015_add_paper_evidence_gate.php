<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_sessions', function (Blueprint $table): void {
            $table->boolean('evidence_eligible')->default(false);
            $table->string('evidence_status', 32)->default('not_eligible');
            $table->string('execution_policy_hash', 64)->nullable();
            $table->timestamp('evidence_checked_at')->nullable();
            $table->text('evidence_failure_reason')->nullable();
            $table->timestamp('entries_suppressed_at')->nullable();
            $table->text('entries_suppression_reason')->nullable();
            $table->jsonb('evidence_summary_json')->nullable();
            $table->index(['evidence_eligible', 'evidence_status'], 'paper_sessions_evidence_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION enforce_paper_evidence_pins()
                RETURNS trigger AS $$
                BEGIN
                    IF OLD.evidence_eligible AND (
                        OLD.strategy_version_id IS DISTINCT FROM NEW.strategy_version_id OR
                        OLD.universe_version_id IS DISTINCT FROM NEW.universe_version_id OR
                        OLD.execution_policy_hash IS DISTINCT FROM NEW.execution_policy_hash OR
                        OLD.evidence_eligible IS DISTINCT FROM NEW.evidence_eligible
                    ) THEN
                        RAISE EXCEPTION 'evidence-eligible paper session pins are immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER paper_sessions_evidence_pins BEFORE UPDATE ON paper_sessions FOR EACH ROW EXECUTE FUNCTION enforce_paper_evidence_pins()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS paper_sessions_evidence_pins ON paper_sessions');
            DB::statement('DROP FUNCTION IF EXISTS enforce_paper_evidence_pins()');
        }
        Schema::table('paper_sessions', function (Blueprint $table): void {
            $table->dropIndex('paper_sessions_evidence_status_idx');
            $table->dropColumn([
                'evidence_eligible', 'evidence_status', 'execution_policy_hash',
                'evidence_checked_at', 'evidence_failure_reason', 'entries_suppressed_at',
                'entries_suppression_reason', 'evidence_summary_json',
            ]);
        });
    }
};

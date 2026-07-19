<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holdout_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_experiment_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('holdout_start');
            $table->timestamp('holdout_end');
            $table->string('status', 24)->default('locked');
            $table->string('content_hash', 64)->unique();
            $table->foreignId('authorized_strategy_version_id')->nullable()->constrained('strategy_versions')->restrictOnDelete();
            $table->foreignId('research_manifest_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('engine_job_id')->nullable()->unique();
            $table->foreign('engine_job_id')->references('id')->on('engine_jobs')->restrictOnDelete();
            $table->string('candidate_hash', 64)->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->string('engine_version', 64)->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->string('authorization_idempotency_key', 64)->nullable()->unique();
            $table->string('authorized_by')->nullable();
            $table->text('purpose')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->jsonb('terminal_result_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'holdout_start'], 'holdout_intervals_status_start_idx');
            $table->index(['revealed_at', 'holdout_start', 'holdout_end'], 'holdout_intervals_revealed_range_idx');
        });

        Schema::create('holdout_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('holdout_interval_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('strategy_experiment_id')->constrained()->restrictOnDelete();
            $table->foreignId('strategy_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('research_manifest_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('engine_job_id')->nullable();
            $table->foreign('engine_job_id')->references('id')->on('engine_jobs')->restrictOnDelete();
            $table->string('candidate_hash', 64)->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->string('engine_version', 64)->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->string('actor');
            $table->text('purpose');
            $table->string('idempotency_key', 64);
            $table->jsonb('details_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['holdout_interval_id', 'event_type', 'idempotency_key'], 'holdout_access_events_idempotency_unique');
            $table->index(['event_type', 'occurred_at'], 'holdout_access_events_type_time_idx');
        });

        DB::table('strategy_experiments')->orderBy('id')->get()->each(function (object $experiment): void {
            $identity = [
                'schema_version' => '1.0',
                'experiment_hash' => $experiment->content_hash,
                'holdout_start' => (string) $experiment->holdout_start,
                'holdout_end' => (string) $experiment->holdout_end,
            ];
            DB::table('holdout_intervals')->insert([
                'strategy_experiment_id' => $experiment->id,
                'holdout_start' => $experiment->holdout_start,
                'holdout_end' => $experiment->holdout_end,
                'status' => 'locked',
                'content_hash' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(<<<'SQL'
                ALTER TABLE holdout_intervals
                ADD CONSTRAINT holdout_intervals_revealed_overlap_excl
                EXCLUDE USING gist (tsrange(holdout_start, holdout_end, '[)') WITH &&)
                WHERE (revealed_at IS NOT NULL)
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION enforce_holdout_interval_lifecycle()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'holdout intervals are append-only';
                    END IF;
                    IF OLD.strategy_experiment_id <> NEW.strategy_experiment_id
                       OR OLD.holdout_start <> NEW.holdout_start
                       OR OLD.holdout_end <> NEW.holdout_end
                       OR OLD.content_hash <> NEW.content_hash THEN
                        RAISE EXCEPTION 'holdout interval identity is immutable';
                    END IF;
                    IF OLD.status IN ('passed', 'failed', 'inconclusive') THEN
                        RAISE EXCEPTION 'terminal holdout results are immutable';
                    END IF;
                    IF OLD.status <> 'locked' AND (
                        OLD.authorized_strategy_version_id IS DISTINCT FROM NEW.authorized_strategy_version_id OR
                        OLD.research_manifest_id IS DISTINCT FROM NEW.research_manifest_id OR
                        OLD.candidate_hash IS DISTINCT FROM NEW.candidate_hash OR
                        OLD.manifest_hash IS DISTINCT FROM NEW.manifest_hash OR
                        OLD.engine_version IS DISTINCT FROM NEW.engine_version OR
                        OLD.code_hash IS DISTINCT FROM NEW.code_hash OR
                        OLD.authorization_idempotency_key IS DISTINCT FROM NEW.authorization_idempotency_key OR
                        OLD.authorized_by IS DISTINCT FROM NEW.authorized_by OR
                        OLD.purpose IS DISTINCT FROM NEW.purpose OR
                        OLD.authorized_at IS DISTINCT FROM NEW.authorized_at
                    ) THEN
                        RAISE EXCEPTION 'holdout authorization identity is immutable';
                    END IF;
                    IF OLD.status <> NEW.status AND NOT (
                        (OLD.status = 'locked' AND NEW.status = 'authorized') OR
                        (OLD.status = 'authorized' AND NEW.status = 'running') OR
                        (OLD.status = 'running' AND NEW.status IN ('passed', 'failed', 'inconclusive'))
                    ) THEN
                        RAISE EXCEPTION 'invalid holdout lifecycle transition';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER holdout_intervals_lifecycle BEFORE UPDATE OR DELETE ON holdout_intervals FOR EACH ROW EXECUTE FUNCTION enforce_holdout_interval_lifecycle()');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_holdout_access_event_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'holdout access events are append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER holdout_access_events_immutable BEFORE UPDATE OR DELETE ON holdout_access_events FOR EACH ROW EXECUTE FUNCTION reject_holdout_access_event_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS holdout_access_events_immutable ON holdout_access_events');
            DB::statement('DROP TRIGGER IF EXISTS holdout_intervals_lifecycle ON holdout_intervals');
            DB::statement('DROP FUNCTION IF EXISTS reject_holdout_access_event_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS enforce_holdout_interval_lifecycle()');
        }
        Schema::dropIfExists('holdout_access_events');
        Schema::dropIfExists('holdout_intervals');
    }
};

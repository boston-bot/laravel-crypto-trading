<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('schema_version', 16)->default('1.0');
            $table->string('name');
            $table->string('status', 24);
            $table->foreignId('universe_version_id')->constrained()->restrictOnDelete();
            $table->string('objective', 64);
            $table->jsonb('constraints_json');
            $table->unsignedInteger('search_budget');
            $table->jsonb('seeds_json');
            $table->jsonb('regimes_json');
            $table->jsonb('cost_policy_json');
            $table->jsonb('attribution_policy_json');
            $table->jsonb('benchmark_policy_json');
            $table->string('execution_policy_version', 64);
            $table->string('execution_policy_hash', 64);
            $table->timestamp('development_start');
            $table->timestamp('development_end');
            $table->timestamp('holdout_start');
            $table->timestamp('holdout_end');
            $table->string('content_hash', 64)->unique();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'strategy_experiments_status_created_idx');
        });

        Schema::create('strategy_experiment_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_experiment_id')->constrained()->cascadeOnDelete();
            $table->string('candidate_key', 64);
            $table->string('family', 64);
            $table->unsignedInteger('search_order');
            $table->unsignedInteger('search_budget');
            $table->string('status', 24)->default('queued');
            $table->jsonb('specification_json');
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['strategy_experiment_id', 'candidate_key'], 'strategy_experiment_candidates_key_unique');
            $table->unique(['strategy_experiment_id', 'content_hash'], 'strategy_experiment_candidates_hash_unique');
        });

        Schema::table('strategy_versions', function (Blueprint $table): void {
            $table->foreignId('strategy_experiment_candidate_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_strategy_version_id')->nullable()->constrained('strategy_versions')->restrictOnDelete();
            $table->string('version_role', 24)->nullable();
            $table->timestamp('calibration_start')->nullable();
            $table->timestamp('calibration_end')->nullable();
            $table->boolean('is_deployable')->default(false);
        });

        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->foreignId('strategy_experiment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('strategy_experiment_candidate_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('evaluation_stage', 24)->default('development');
            $table->string('execution_policy_hash', 64)->nullable();
            $table->string('result_manifest_hash', 64)->nullable();
            $table->index(['strategy_experiment_id', 'evaluation_stage', 'status'], 'backtest_runs_experiment_stage_idx');
        });

        Schema::table('engine_jobs', function (Blueprint $table): void {
            $table->foreignId('backtest_run_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_experiment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('strategy_experiment_candidate_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::table('backtest_run_metrics', function (Blueprint $table): void {
            $table->dropUnique('backtest_run_metrics_unique_metric');
            $table->string('dimension_key', 128)->default('aggregate');
            $table->unique(
                ['backtest_run_id', 'metric_name', 'metric_group', 'dimension_key'],
                'backtest_run_metrics_dimension_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_experiment_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'strategy experiments and candidate specifications are immutable';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER strategy_experiments_immutable BEFORE UPDATE OR DELETE ON strategy_experiments FOR EACH ROW EXECUTE FUNCTION reject_experiment_mutation()');
            DB::statement('CREATE TRIGGER strategy_experiment_candidates_immutable BEFORE UPDATE OR DELETE ON strategy_experiment_candidates FOR EACH ROW EXECUTE FUNCTION reject_experiment_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS strategy_experiments_immutable ON strategy_experiments');
            DB::statement('DROP TRIGGER IF EXISTS strategy_experiment_candidates_immutable ON strategy_experiment_candidates');
            DB::statement('DROP FUNCTION IF EXISTS reject_experiment_mutation()');
        }

        Schema::table('backtest_run_metrics', function (Blueprint $table): void {
            $table->dropUnique('backtest_run_metrics_dimension_unique');
            $table->dropColumn('dimension_key');
            $table->unique(['backtest_run_id', 'metric_name', 'metric_group'], 'backtest_run_metrics_unique_metric');
        });
        Schema::table('engine_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('backtest_run_id');
            $table->dropConstrainedForeignId('strategy_experiment_id');
            $table->dropConstrainedForeignId('strategy_experiment_candidate_id');
        });
        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->dropIndex('backtest_runs_experiment_stage_idx');
            $table->dropConstrainedForeignId('strategy_experiment_id');
            $table->dropConstrainedForeignId('strategy_experiment_candidate_id');
            $table->dropColumn(['evaluation_stage', 'execution_policy_hash', 'result_manifest_hash']);
        });
        Schema::table('strategy_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('strategy_experiment_candidate_id');
            $table->dropConstrainedForeignId('parent_strategy_version_id');
            $table->dropColumn(['version_role', 'calibration_start', 'calibration_end', 'is_deployable']);
        });
        Schema::dropIfExists('strategy_experiment_candidates');
        Schema::dropIfExists('strategy_experiments');
    }
};

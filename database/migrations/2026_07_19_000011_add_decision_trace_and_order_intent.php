<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_evaluations', function (Blueprint $table): void {
            $table->string('evaluation_resolution', 40)->default('hold')->after('action');
            $table->string('strategy_family')->nullable();
            $table->string('strategy_definition_version')->nullable();
            $table->string('decision_trace_hash', 64)->nullable();
            $table->string('evidence_hash', 64)->nullable();
            $table->string('parameter_hash', 64)->nullable();
            $table->jsonb('decision_trace_json')->nullable();
            $table->jsonb('portfolio_target_json')->nullable();
            $table->jsonb('order_intent_json')->nullable();
            $table->index(['evaluation_resolution', 'created_at'], 'asset_evaluations_resolution_idx');
        });
        Schema::table('trade_decisions', function (Blueprint $table): void {
            $table->string('evaluation_resolution', 40)->nullable();
            $table->string('order_intent_hash', 64)->nullable();
            $table->jsonb('order_intent_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trade_decisions', fn (Blueprint $table) => $table->dropColumn(['evaluation_resolution', 'order_intent_hash', 'order_intent_json']));
        Schema::table('asset_evaluations', function (Blueprint $table): void {
            $table->dropIndex('asset_evaluations_resolution_idx');
            $table->dropColumn(['evaluation_resolution', 'strategy_family', 'strategy_definition_version', 'decision_trace_hash', 'evidence_hash', 'parameter_hash', 'decision_trace_json', 'portfolio_target_json', 'order_intent_json']);
        });
    }
};

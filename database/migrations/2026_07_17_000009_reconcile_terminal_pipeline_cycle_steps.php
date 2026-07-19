<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pipeline_cycle_steps')
            ->whereIn('status', ['queued', 'running', 'waiting_engine'])
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('pipeline_cycles')
                    ->whereColumn('pipeline_cycles.id', 'pipeline_cycle_steps.pipeline_cycle_id')
                    ->where('pipeline_cycles.status', 'completed');
            })
            ->update([
                'status' => 'completed',
                'reason' => 'Reconciled because the parent cycle had already completed.',
                'completed_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This repairs stale display state; restoring a false running state would be unsafe.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_execution_automation', function (Blueprint $t) {
            $t->id();
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->unsignedInteger('version');
            $t->boolean('enabled');
            $t->foreignId('mcp_connection_id')->constrained();
            $t->unsignedInteger('auth_generation');
            $t->string('policy_fingerprint', 64)->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_id')->unique();
            $t->timestampTz('recorded_at', 6);
            $t->unique(['execution_account_id', 'version']);
        });
        Schema::create('mcp_execution_cycles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('execution_account_id')->constrained('mcp_execution_accounts');
            $t->foreignId('automation_id')->constrained('mcp_execution_automation');
            $t->timestampTz('slot_at', 6);
            $t->string('status', 24);
            $t->string('reason', 96)->nullable();
            $t->unsignedInteger('evaluated')->default(0);
            $t->unsignedInteger('attempted')->default(0);
            $t->timestampTz('started_at', 6);
            $t->timestampTz('finished_at', 6)->nullable();
            $t->unique(['execution_account_id', 'slot_at']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE TRIGGER execution_automation_immutable BEFORE UPDATE OR DELETE ON "mcp_trading"."mcp_execution_automation" FOR EACH ROW EXECUTE FUNCTION "mcp_trading".execution_history_immutable()');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_execution_automation" ADD CONSTRAINT execution_automation_version CHECK (version > 0 AND auth_generation >= 0 AND (NOT enabled OR policy_fingerprint IS NOT NULL))');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only execution activation audit; restore/reconcile with writes disabled.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_earnings_calendar_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->date('start_date');
            $t->unsignedSmallInteger('days');
            $t->string('status', 16);
            $t->string('reason', 64);
            $t->unsignedInteger('event_count')->nullable();
            $t->text('payload');
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'earnings_calendar_request');
            $t->index(['mcp_connection_id', 'scope', 'status', 'recorded_at'], 'earnings_calendar_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_earnings_calendar_reads" ADD CONSTRAINT earnings_calendar_states CHECK (status IN (\'complete\',\'incomplete\') AND days BETWEEN 1 AND 31 AND ((status = \'complete\') = (event_count IS NOT NULL)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_earnings_calendar_reads');
    }
};

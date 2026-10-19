<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_instrument_classifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('symbol', 16);
            $t->string('provider', 32);
            $t->string('status', 16);
            $t->string('basis', 48)->nullable();
            $t->string('reason', 64)->nullable();
            $t->text('payload');
            $t->timestampTz('source_at', 6)->nullable();
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'paper_classification_request');
            $t->index(['mcp_connection_id', 'scope', 'symbol', 'recorded_at'], 'paper_classification_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_instrument_classifications" ADD CONSTRAINT paper_classification_states CHECK (status IN (\'complete\',\'incomplete\') AND (status = \'complete\') = (basis IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_instrument_classifications');
    }
};

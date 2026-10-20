<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_session_valuations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->unsignedInteger('auth_generation');
            $t->date('session_date');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('rule_version', 48);
            $t->decimal('equity', 38, 18);
            $t->string('input_fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('cutoff', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['paper_portfolio_id', 'auth_generation', 'session_date'], 'session_valuation_identity');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_session_valuations" ADD CONSTRAINT session_valuation_positive CHECK (equity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_session_valuations');
    }
};

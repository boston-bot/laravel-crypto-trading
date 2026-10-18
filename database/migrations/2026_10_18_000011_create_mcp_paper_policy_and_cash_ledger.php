<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_portfolios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->decimal('starting_cash', 38, 18);
            $t->decimal('cash', 38, 18);
            $t->decimal('reserved_cash', 38, 18)->default(0);
            $t->unsignedBigInteger('ledger_version')->default(1);
            $t->unsignedInteger('policy_revision')->default(1);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['mcp_connection_id', 'scope']);
        });
        Schema::create('mcp_paper_policy_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('mandate_version_id')->constrained('mcp_paper_mandate_versions');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('revision');
            $t->uuid('request_id');
            $t->text('snapshot');
            $t->timestampTz('applied_at');
            $t->unique(['paper_portfolio_id', 'revision']);
            $t->unique(['paper_portfolio_id', 'request_id']);
        });
        Schema::create('mcp_paper_cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_portfolio_id')->constrained('mcp_paper_portfolios');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('reference', 100)->unique();
            $t->string('kind', 32);
            $t->decimal('amount', 38, 18);
            $t->decimal('balance_after', 38, 18);
            $t->unsignedBigInteger('ledger_version');
            $t->timestampTz('recorded_at');
            $t->unique(['paper_portfolio_id', 'ledger_version']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_portfolios" ADD CONSTRAINT paper_cash_bounds CHECK (starting_cash > 0 AND cash >= reserved_cash AND reserved_cash >= 0 AND ledger_version >= 1 AND policy_revision >= 1)');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_cash_entries" ADD CONSTRAINT paper_entry_bounds CHECK (balance_after >= 0 AND ledger_version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_cash_entries');
        Schema::dropIfExists('mcp_paper_policy_applications');
        Schema::dropIfExists('mcp_paper_portfolios');
    }
};

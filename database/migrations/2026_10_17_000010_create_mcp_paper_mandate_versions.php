<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_paper_mandate_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedBigInteger('restored_from_id')->nullable();
            $t->text('payload');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['mcp_connection_id', 'scope', 'version'], 'paper_mandate_version_unique');
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'paper_mandate_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_paper_mandate_versions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('disconnected');
            $table->unsignedInteger('auth_generation')->default(0);
            $table->string('error_code')->nullable();
            $table->string('protocol_version')->nullable();
            $table->text('capability_manifest')->nullable();
            $table->string('capability_fingerprint', 64)->nullable();
            $table->timestampTz('capabilities_checked_at')->nullable();
            $table->text('bound_account_id')->nullable();
            $table->string('account_role')->nullable();
            $table->foreignId('bound_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('bound_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('mcp_oauth_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mcp_connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('client_id');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('oauth_metadata');
            $table->timestampsTz();
        });
        Schema::create('mcp_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mcp_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->string('session_hash', 64);
            $table->text('verifier');
            $table->text('redirect_uri');
            $table->text('client_id');
            $table->text('oauth_metadata');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_attempts');
        Schema::dropIfExists('mcp_oauth_credentials');
        Schema::dropIfExists('mcp_connections');
    }
};

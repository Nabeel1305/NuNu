<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Prefix dialed ahead of the code on the shared voice number, so
            // the gateway can tell which tenant a call belongs to.
            $table->char('short_code', 4)->unique();
            $table->string('status', 20)->default('active');          // active | suspended
            $table->string('environment', 20)->default('sandbox');    // sandbox | live
            $table->string('voice_mode', 20)->default('own');         // own | shared
            $table->string('settlement_adapter', 40)->default('sandbox');
            $table->unsignedSmallInteger('code_ttl_minutes')->default(10);
            $table->boolean('bind_caller')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('prefix', 8)->unique();
            $table->char('key_hash', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');                 // encrypted via model cast
            $table->json('events')->nullable();     // null = every event
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('voice_numbers', function (Blueprint $table) {
            $table->id();
            // null tenant = the shared pool, resolved by code prefix. Cascade, not
            // null-on-delete: a deleted tenant's own number must not become shared.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('africastalking');
            $table->string('number', 32)->unique();
            $table->char('webhook_token_hash', 64);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_numbers');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('tenants');
    }
};

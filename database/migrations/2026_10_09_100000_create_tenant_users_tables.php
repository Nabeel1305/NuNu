<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People who sign in to a tenant's own portal. Platform operators stay in `admins`.
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();               // null until the invitation is accepted
            $table->string('role', 20)->default('viewer');      // owner | developer | viewer
            $table->boolean('is_active')->default(true);
            $table->text('totp_secret')->nullable();            // encrypted via model cast
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['tenant_id', 'role']);
        });

        // One-time links that let a person choose their own password. Only a hash is stored.
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        // The portal lists newest first within one tenant.
        Schema::table('transactions', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
        Schema::table('payment_codes', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'created_at']));
        Schema::table('payment_codes', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'created_at']));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'created_at']));
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_users');
    }
};

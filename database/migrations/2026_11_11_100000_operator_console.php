<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 20)->default('owner')->after('email');           // owner | support | finance
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('session_epoch')->default(1);                   // bumping it signs the operator out everywhere
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('admin_email', 191)->nullable();
            $table->string('event', 80)->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('subject', 120)->nullable();
            $table->string('description', 500);
            $table->text('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('outcome', 20)->default('ok');                           // ok | denied | failed
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->text('allowed_ips')->nullable();                                // comma-separated; empty = any address
            $table->string('last_used_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', fn (Blueprint $t) => $t->dropColumn(['expires_at', 'allowed_ips', 'last_used_ip']));
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_audit_logs');
        Schema::table('admins', fn (Blueprint $t) => $t->dropColumn(['role', 'last_login_at', 'must_change_password', 'session_epoch', 'password_changed_at']));
    }
};

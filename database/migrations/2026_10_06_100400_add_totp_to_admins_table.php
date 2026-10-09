<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();            // encrypted via model cast
            $table->timestamp('totp_confirmed_at')->nullable(); // null until the admin proves their app works
            $table->unsignedBigInteger('totp_last_step')->nullable(); // newest step used, so a code cannot be replayed
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step']);
        });
    }
};

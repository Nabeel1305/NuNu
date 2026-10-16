<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Re-enrolling a phone gives it a new key and restarts its counter, so "this counter was used" has to
        // be scoped to the key it was signed with, or the first payment after a re-enrol would collide with
        // an old row.
        Schema::table('payment_codes', function (Blueprint $table) {
            $table->unsignedSmallInteger('device_key_version')->nullable()->after('device_counter');
        });

        \Illuminate\Support\Facades\DB::table('payment_codes')->where('origin', 'device')->update(['device_key_version' => 1]);

        Schema::table('payment_codes', function (Blueprint $table) {
            $table->dropUnique('payment_codes_device_counter_unique');
            $table->unique(['tenant_id', 'offline_device_id', 'device_key_version', 'device_counter'], 'payment_codes_device_counter_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_codes', function (Blueprint $table) {
            $table->dropUnique('payment_codes_device_counter_unique');
            $table->unique(['tenant_id', 'offline_device_id', 'device_counter'], 'payment_codes_device_counter_unique');
            $table->dropColumn('device_key_version');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A phone that may build device-signed codes for a subscriber. Its secret key is never stored:
        // it is derived on demand from a platform secret plus these fields (see DeviceKeyService).
        Schema::create('offline_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 191);                    // the tenant's own identifier for this installation
            $table->char('short_id', 8);                         // what the phone puts in the code
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->string('status', 12)->default('active');     // active | revoked
            $table->unsignedInteger('last_counter')->default(0); // highest counter accepted, so a code works once
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'short_id']);
            $table->unique(['tenant_id', 'subscriber_id', 'device_id']);
        });

        // Merchants need a short numeric id the phone can put in a code (scanned from the merchant's QR).
        Schema::table('merchants', function (Blueprint $table) {
            $table->char('short_id', 8)->nullable()->after('reference');
            $table->unique(['tenant_id', 'short_id']);
        });

        DB::table('merchants')->whereNull('short_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $short = (string) random_int(10000000, 99999999);
                } while (DB::table('merchants')->where('tenant_id', $row->tenant_id)->where('short_id', $short)->exists());

                DB::table('merchants')->where('id', $row->id)->update(['short_id' => $short]);
            }
        });

        Schema::table('payment_codes', function (Blueprint $table) {
            $table->string('origin', 10)->default('issued')->after('uuid');   // issued (API) | device (signed by a phone)
            $table->foreignId('offline_device_id')->nullable()->after('origin')->constrained('offline_devices')->nullOnDelete();
            $table->unsignedInteger('device_counter')->nullable()->after('offline_device_id');
            // Backstop for the row lock: a counter can only ever be spent once per device.
            $table->unique(['tenant_id', 'offline_device_id', 'device_counter'], 'payment_codes_device_counter_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_codes', function (Blueprint $table) {
            $table->dropUnique('payment_codes_device_counter_unique');
            $table->dropConstrainedForeignId('offline_device_id');
            $table->dropColumn(['origin', 'device_counter']);
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'short_id']);
            $table->dropColumn('short_id');
        });
        Schema::dropIfExists('offline_devices');
    }
};

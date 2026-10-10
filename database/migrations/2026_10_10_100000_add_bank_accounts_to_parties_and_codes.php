<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The API now requires an account number and bank code for every subscriber and merchant. The
        // columns stay nullable only so rows created before this change survive; such a subscriber or
        // merchant must be re-registered before it can be used for a new code.
        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('account_number', 20)->nullable()->after('phone_normalized');
            $table->string('bank_code', 10)->nullable()->after('account_number');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('account_number', 20)->nullable()->after('name');
            $table->string('bank_code', 10)->nullable()->after('account_number');
            // The tenant's own reference for the account is now optional extra detail.
            $table->string('account_reference')->nullable()->change();
        });

        // Both accounts are frozen on the code when it is issued, so changing a merchant later can
        // never redirect a payment that is already in flight. Nullable for codes issued before this.
        Schema::table('payment_codes', function (Blueprint $table) {
            $table->string('source_account_number', 20)->nullable()->after('source_account_reference');
            $table->string('source_bank_code', 10)->nullable()->after('source_account_number');
            $table->string('destination_account_number', 20)->nullable()->after('source_bank_code');
            $table->string('destination_bank_code', 10)->nullable()->after('destination_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('payment_codes', function (Blueprint $table) {
            $table->dropColumn(['source_account_number', 'source_bank_code', 'destination_account_number', 'destination_bank_code']);
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['account_number', 'bank_code']);
        });
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['account_number', 'bank_code']);
        });
    }
};

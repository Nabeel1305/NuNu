<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Full number with country code, digits only. Lets a redemption ask "is this caller a
            // known subscriber?" with an exact, indexed lookup.
            $table->string('phone_normalized', 20)->nullable()->after('phone');
            $table->index(['tenant_id', 'phone_normalized']);
        });

        DB::table('subscribers')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('subscribers')->where('id', $row->id)->update(['phone_normalized' => PhoneNumber::normalize($row->phone) ?: null]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'phone_normalized']);
            $table->dropColumn('phone_normalized');
        });
    }
};

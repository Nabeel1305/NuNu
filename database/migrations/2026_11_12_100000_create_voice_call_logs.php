<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per callback from the voice provider: what arrived and what the platform did with it.
        // The digits themselves are never stored (they are payment codes), only how many there were.
        Schema::create('voice_call_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('destination', 32)->nullable();
            $table->string('caller_tail', 8)->nullable();         // last four digits of the caller
            $table->string('session_id', 100)->nullable();
            $table->string('step', 12);                           // prompt | digits
            $table->unsignedSmallInteger('digits_length')->nullable();
            $table->string('outcome', 20);                        // prompt | accepted | rejected | denied | throttled | no_tenant
            $table->string('reason', 40)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_logs');
    }
};

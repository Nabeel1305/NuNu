<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tenant holding the hash of its newest audit entry. Writers lock
     * this row to take turns. It must not be the tenants row: every child insert
     * (codes, transactions, audit entries) takes a shared lock on that row for its
     * foreign key, and two writers each holding that shared lock while asking for
     * an exclusive one deadlock. Nothing may reference this table with a foreign key.
     */
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->char('last_hash', 64)->nullable();
        });

        DB::statement('INSERT INTO audit_chain_heads (tenant_id, last_hash)
            SELECT t.id, (SELECT a.hash FROM audit_logs a WHERE a.tenant_id = t.id ORDER BY a.id DESC LIMIT 1) FROM tenants t');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
    }
};

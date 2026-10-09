<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference');            // the tenant's own id for the payer
            $table->string('phone', 32)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->string('name');
            $table->string('account_reference');    // tenant account that is credited
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
        });

        Schema::create('payment_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscriber_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('source_account_reference');
            // HMAC of the dialed digits. The plain code is returned once at
            // issue time and never stored.
            $table->char('code_hash', 64);
            $table->string('state', 20)->default('issued');
            $table->string('hold_reference')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->string('caller_number', 32)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code_hash']);
            $table->index(['state', 'expires_at']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_code_id')->constrained();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');   // pending | settled | failed
            $table->string('settlement_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            // Encrypted at rest: a replayed issue response contains the plain code.
            $table->text('response_body')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_type', 60);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');   // pending | delivered | failed
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('description');
            // Text, not JSON: MySQL reorders JSON keys, which would change the hashed bytes.
            $table->text('metadata')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'webhook_deliveries', 'idempotency_keys', 'transactions', 'payment_codes', 'merchants', 'subscribers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

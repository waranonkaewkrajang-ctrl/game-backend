<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for write actions coming from the unified admin (internal API).
 * New table only — nothing existing is altered.
 * One row per Idempotency-Key: a repeated key returns the stored result instead of running the action again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_admin_operations', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 100)->unique();
            $t->unsignedBigInteger('admin_id');
            $t->string('actor', 80)->nullable();          // unified-admin username (audit only)
            $t->string('method', 10);
            $t->string('path', 255);
            $t->char('request_hash', 64);
            $t->string('status', 20)->default('processing'); // processing | done
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->longText('response_body')->nullable();
            $t->timestamps();
            $t->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_admin_operations');
    }
};

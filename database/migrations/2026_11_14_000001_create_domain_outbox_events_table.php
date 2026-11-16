<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (ใหม่ — additive): event ทุกตัวที่จะส่งไป admin-api ถูกเขียนลงตารางนี้
 * "ใน transaction เดียวกับธุรกรรมจริง" แล้ว dispatcher ในเครื่องค่อย push ออกไปหลัง commit
 * ไม่มีการแก้ตาราง/ข้อมูลการเงินเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_outbox_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->string('event_type', 64);
            $t->string('aggregate_type', 32);
            $t->string('aggregate_id', 64);
            $t->unsignedBigInteger('aggregate_version');      // logical clock (µs) — ใช้เรียงลำดับฝั่งผู้รับ
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->json('payload');
            $t->string('status', 16)->default('pending');      // pending | delivered | failed | dead
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->string('lock_token', 36)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['status', 'next_attempt_at']);
            $t->index(['aggregate_type', 'aggregate_id', 'id']);
            $t->index('lock_token');
            $t->index('delivered_at');
        });

        // จุด checkpoint ของการนำเข้าสมาชิกครั้งแรก (resume ได้เมื่อถูกขัดจังหวะ)
        Schema::create('domain_export_runs', function (Blueprint $t) {
            $t->id();
            $t->string('name', 64)->unique();
            $t->string('status', 16)->default('running');      // running | completed | failed
            $t->unsignedBigInteger('last_id')->default(0);
            $t->unsignedBigInteger('max_id')->default(0);
            $t->unsignedBigInteger('sent')->default(0);
            $t->unsignedBigInteger('duplicates')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_export_runs');
        Schema::dropIfExists('domain_outbox_events');
    }
};

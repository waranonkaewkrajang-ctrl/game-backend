<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified admin identity: one LINE admin staff account ↔ one game `admins` row created automatically for it.
 * Additive only. Historical admins rows and approved_by references are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_staff_admins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('line_staff_id')->unique();   // users.id in the LINE admin (PostgreSQL)
            $table->foreignId('admin_id')->unique()->constrained('admins');
            $table->string('line_username', 100)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_staff_admins');
    }
};

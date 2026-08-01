<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('processing_by')->nullable()->after('approved_at');
            $table->timestamp('processing_at')->nullable()->after('processing_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn(['processing_by', 'processing_at']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_statements', 'from_bank_code')) {
                $table->string('from_bank_code', 20)->nullable()->after('from_account');
            }
            if (!Schema::hasColumn('bank_statements', 'bank_name')) {
                $table->string('bank_name', 150)->nullable()->after('bank_account');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropColumn(['from_bank_code', 'bank_name']);
        });
    }
};
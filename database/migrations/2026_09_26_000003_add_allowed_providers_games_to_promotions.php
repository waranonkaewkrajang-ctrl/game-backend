<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->json('allowed_providers')->nullable()->after('allowed_categories');
            $table->json('allowed_games')->nullable()->after('allowed_providers');
        });

        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->json('allowed_providers')->nullable()->after('allowed_categories');
            $table->json('allowed_games')->nullable()->after('allowed_providers');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', fn (Blueprint $t) => $t->dropColumn(['allowed_providers', 'allowed_games']));
        Schema::table('promotion_claims', fn (Blueprint $t) => $t->dropColumn(['allowed_providers', 'allowed_games']));
    }
};
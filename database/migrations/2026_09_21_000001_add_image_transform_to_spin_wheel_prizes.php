<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spin_wheel_prizes', function (Blueprint $table) {
            $table->unsignedSmallInteger('img_scale')->default(100)->after('image_url');
            $table->smallInteger('img_x')->default(0)->after('img_scale');
            $table->smallInteger('img_y')->default(0)->after('img_x');
            $table->smallInteger('img_rotate')->default(0)->after('img_y');
        });
    }

    public function down(): void
    {
        Schema::table('spin_wheel_prizes', function (Blueprint $table) {
            $table->dropColumn(['img_scale', 'img_x', 'img_y', 'img_rotate']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('amb_username')->nullable()->unique()->after('username');
    });

    // อัปเดต user เดิมทั้งหมด
    foreach (DB::table('users')->orderBy('id')->get() as $user) {
        DB::table('users')->where('id', $user->id)->update([
            'amb_username' => 'sn' . str_pad($user->id, 5, '0', STR_PAD_LEFT),
        ]);
    }
}

    public function down(): void
  {
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('amb_username');
    });
  }
};

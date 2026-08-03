<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // === 1. เพิ่ม image_url ในตาราง prizes ===
        Schema::table('spin_wheel_prizes', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('icon');
        });

        // === 2. เพิ่ม multiplier + final_value ในตาราง history ===
        Schema::table('spin_wheel_history', function (Blueprint $table) {
            $table->string('spin_type')->default('free')->after('is_claimed');
            $table->decimal('multiplier', 8, 2)->default(1)->after('spin_type');
            $table->decimal('final_value', 15, 2)->default(0)->after('multiplier');
        });

        // === 3. เพิ่ม ticket_balance + point_balance ใน wallets ===
        Schema::table('wallets', function (Blueprint $table) {
            $table->integer('ticket_balance')->default(0)->after('balance');
            $table->decimal('point_balance', 15, 2)->default(0)->after('ticket_balance');
        });

        // === 4. ตารางตัวคูณ (Multiplier) ===
        Schema::create('spin_wheel_multipliers', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->decimal('value', 8, 2)->default(1);
            $table->string('color')->default('#facc15');
            $table->decimal('probability', 5, 2)->default(10);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // === 5. เพิ่ม Settings เริ่มต้น ===
        $settings = [
            'spin_ticket_cost'  => '1',
            'spin_point_cost'   => '500',
            'spin_free_enabled' => 'true',
        ];
        foreach ($settings as $key => $value) {
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // === 6. เพิ่ม Multipliers เริ่มต้น ===
        $multipliers = [
            ['label' => '1x',  'value' => 1,  'probability' => 40, 'sort_order' => 1, 'color' => '#94a3b8'],
            ['label' => '2x',  'value' => 2,  'probability' => 25, 'sort_order' => 2, 'color' => '#22c55e'],
            ['label' => '10x', 'value' => 10, 'probability' => 15, 'sort_order' => 3, 'color' => '#3b82f6'],
            ['label' => '20x', 'value' => 20, 'probability' => 10, 'sort_order' => 4, 'color' => '#a855f7'],
            ['label' => '30x', 'value' => 30, 'probability' => 7,  'sort_order' => 5, 'color' => '#f97316'],
            ['label' => '40x', 'value' => 40, 'probability' => 3,  'sort_order' => 6, 'color' => '#ef4444'],
        ];
        foreach ($multipliers as $m) {
            \App\Models\SpinWheelMultiplier::create($m);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_wheel_multipliers');

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['ticket_balance', 'point_balance']);
        });

        Schema::table('spin_wheel_history', function (Blueprint $table) {
            $table->dropColumn(['spin_type', 'multiplier', 'final_value']);
        });

        Schema::table('spin_wheel_prizes', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
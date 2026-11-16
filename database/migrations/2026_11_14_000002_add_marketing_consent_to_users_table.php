<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ความยินยอมรับข่าวสารการตลาดแยกช่องทาง + การขอยกเลิก (ใหม่ — nullable ทั้งหมด ไม่กระทบข้อมูลเดิม)
 * NULL = ยังไม่เคยให้ความยินยอม → CRM จะไม่ติดต่อ (ถ้าเปิด require_consent ซึ่งเป็นค่าเริ่มต้น)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('marketing_sms_consent_at')->nullable();
            $t->timestamp('marketing_line_consent_at')->nullable();
            $t->timestamp('marketing_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['marketing_sms_consent_at', 'marketing_line_consent_at', 'marketing_opted_out_at']);
        });
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'product_id', 'game_code', 'game_name', 'game_name_th',
        'category', 'type', 'image_url', 'rank', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // 🟢 โค้ดที่ต้องเพิ่ม: เพื่อแปลง Path รูปภาพให้เป็น URL เต็มเสมอ
    public function getImageUrlAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        // ถ้าเป็น URL แบบเต็ม (มี http) อยู่แล้ว ให้ใช้ได้เลย
        if (str_starts_with($value, 'http')) {
            return $value;
        }

        // ถ้าเก็บแค่ path ย่อ ให้เติม URL ของเว็บเข้าไป (อาจจะต้องเปลี่ยน 'storage/' เป็น path ที่คุณเก็บรูปจริง)
        return asset('storage/' . ltrim($value, '/')); 
    }
}
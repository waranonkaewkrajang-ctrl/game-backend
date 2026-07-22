<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'image_url'];

    // 🟢 เพิ่มโค้ดตัวแปลงนี้เข้าไป
    public function getImageUrlAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        // ถ้าเป็น URL แบบเต็ม (มี http) อยู่แล้ว ให้ใช้ได้เลย
        if (str_starts_with($value, 'http')) {
            return $value;
        }

        // แปลง path ให้เป็น URL เต็ม (กรณีเก็บไว้ในโฟลเดอร์ uploads โดยตรง)
        return asset(ltrim($value, '/')); 
    }
}
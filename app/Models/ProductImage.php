<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'image_url'];

    // 🟢 เปลี่ยนโค้ดตัวแปลงเป็นแบบนี้ เพื่อบังคับใช้ URL ของหลังบ้านเสมอ
    public function getImageUrlAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        // ถ้าเป็น URL แบบเต็ม (มี http) อยู่แล้ว ให้ใช้ได้เลย
        if (str_starts_with($value, 'http')) {
            return $value;
        }

        // ดึง URL จากไฟล์ .env (http://31.97.220.103)
        $baseUrl = rtrim(config('app.url'), '/');
        
        // ลบ / ด้านหน้าออก ป้องกันสแลชเบิ้ล
        $path = ltrim($value, '/');

        // จัดการต่อ URL ให้สมบูรณ์
        return $baseUrl . '/' . $path; 
    }
}
<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::create([
            'username'  => 'superadmin',
            'password'  => Hash::make('admin1234'),
            'name'      => 'Super Admin',
            'role'      => 'super_admin',
            'is_active' => true,
        ]);
    }
}
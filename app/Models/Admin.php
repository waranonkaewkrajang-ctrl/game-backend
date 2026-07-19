<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'username', 'password', 'name', 'role',
        'permissions', 'is_active',
        'last_login_ip', 'last_login_at',
        'two_factor_secret',  
        'two_factor_enabled',  
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'permissions'   => 'array',
        'is_active'     => 'boolean',
        'two_factor_enabled' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissions ?? []);
    }
}
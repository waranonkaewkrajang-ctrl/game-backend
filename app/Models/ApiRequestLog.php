<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_token_id', 'endpoint', 'ip', 'query_value', 'status_code', 'note', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function token()
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }
}
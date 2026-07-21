<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'request_hash',
        'status_code',
        'response_body',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_body' => 'array',
    ];
}

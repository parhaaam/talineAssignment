<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id', 'scope', 'idempotency_key', 'request_hash', 'deposit_id', 'transaction_id',
    'response_status', 'response_body',
])]
class IdempotencyRecord extends Model
{
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}

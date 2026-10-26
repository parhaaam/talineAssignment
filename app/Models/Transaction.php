<?php

namespace App\Models;

use App\Enums\{TransactionStatus, TransactionType};
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['reference', 'currency_id', 'type', 'status', 'amount', 'posted_at'])]
class Transaction extends Model
{
    protected static function booted(): void
    {
        // Posted history is corrected with compensating transactions, never rewritten.
        static::updating(fn () => throw new LogicException('Posted transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted transactions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'posted_at' => 'datetime',
        ];
    }
}

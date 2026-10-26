<?php

namespace App\Models;

use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'wallet_id', 'currency_id', 'transaction_id', 'reference', 'provider', 'provider_authority',
    'provider_reference', 'amount', 'provider_amount', 'provider_currency', 'status',
    'provider_verified_at', 'confirmed_at', 'failed_at',
])]
class Deposit extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'provider_amount' => 'integer',
            'status' => DepositStatus::class,
            'provider_verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

<?php

namespace App\Repositories;

use App\Enums\AccountType;
use App\Models\Account;

class AccountRepository
{
    public function __construct(private readonly Account $model)
    {
    }

    public function firstOrCreateClearing(int $currencyId, string $currencyCode): Account
    {
        return $this->model->newQuery()->firstOrCreate(
            ['type' => AccountType::PaymentClearing->value, 'currency_id' => $currencyId],
            [
                'code' => 'SYSTEM:PAYMENT_CLEARING:'.$currencyCode,
                'name' => "Payment clearing ({$currencyCode})",
            ],
        );
    }

    public function walletAccountId(int $walletId): ?int
    {
        return $this->model->newQuery()->where('wallet_id', $walletId)->value('id');
    }
}

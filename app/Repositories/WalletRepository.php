<?php

namespace App\Repositories;

use App\Enums\AccountType;
use App\Models\{Account, Wallet};
use Illuminate\Support\Facades\DB;

/** Transactional repository: a wallet and its ledger account are created atomically. */
class WalletRepository
{
    public function __construct(
        private readonly Wallet $wallets,
        private readonly Account $accounts,
    ) {
    }

    public function find(int $id): ?Wallet
    {
        return $this->wallets->newQuery()->with('currency')->find($id);
    }

    public function createWithAccount(int $userId, int $currencyId): Wallet
    {
        return DB::transaction(function () use ($userId, $currencyId) {
            $wallet = $this->wallets->newQuery()
                ->where('user_id', $userId)->where('currency_id', $currencyId)->first();

            if ($wallet === null) {
                $wallet = $this->wallets->newQuery()->create([
                    'user_id' => $userId,
                    'currency_id' => $currencyId,
                    'balance' => 0,
                ]);
            }

            $this->accounts->newQuery()->firstOrCreate(
                ['wallet_id' => $wallet->id],
                [
                    'currency_id' => $currencyId,
                    'code' => 'WALLET:'.$wallet->id,
                    'name' => 'Wallet '.$wallet->id,
                    'type' => AccountType::Wallet->value,
                ],
            );

            return $wallet->load('currency');
        });
    }
}

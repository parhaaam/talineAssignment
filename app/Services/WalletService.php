<?php

namespace App\Services;

use App\Exceptions\{Forbidden, WalletNotFound};
use App\Models\Wallet;
use App\Repositories\{CurrencyRepository, WalletRepository};

class WalletService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly CurrencyRepository $currencies,
    ) {
    }

    public function provision(int $userId, string $currencyCode): Wallet
    {
        $currency = $this->currencies->findByCode($currencyCode)
            ?? throw new WalletNotFound("Unknown currency {$currencyCode}.");

        return $this->wallets->createWithAccount($userId, $currency->id);
    }

    public function find(int $walletId): Wallet
    {
        return $this->wallets->find($walletId) ?? throw new WalletNotFound();
    }

    public function requireOwned(int $walletId, int $userId): Wallet
    {
        $wallet = $this->find($walletId);

        if ((int) $wallet->user_id !== $userId) {
            throw new Forbidden();
        }

        return $wallet;
    }
}

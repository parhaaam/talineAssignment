<?php

namespace App\Services;

use App\Exceptions\{CurrencyMismatch, SelfTransfer};
use App\Models\Wallet;
use App\Repositories\LedgerRepository;
use App\Support\Money;

class TransferService
{
    public const SCOPE = 'transfer:create';

    public function __construct(private readonly LedgerRepository $ledger)
    {
    }

    /**
     * Pre-checks on unlocked reads for clear errors; the ledger repository
     * re-validates everything under row locks.
     */
    public function validate(Wallet $sender, Wallet $recipient, mixed $amount): int
    {
        $amount = Money::assertValidOperationAmount($amount);

        if ($sender->id === $recipient->id) {
            throw new SelfTransfer();
        }

        if ($sender->currency_id !== $recipient->currency_id) {
            throw new CurrencyMismatch('Cross-currency transfers are not supported.');
        }

        return $amount;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(int $userId, string $key, string $requestHash, int $fromWalletId, int $toWalletId, int $amount): array
    {
        return $this->ledger->postTransfer([
            'user_id' => $userId,
            'scope' => self::SCOPE,
            'key' => $key,
            'request_hash' => $requestHash,
            'from_wallet_id' => $fromWalletId,
            'to_wallet_id' => $toWalletId,
            'amount' => $amount,
        ]);
    }
}

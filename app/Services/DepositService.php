<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Exceptions\{DepositNotFound, IdempotencyKeyTaken};
use App\Models\{Deposit, Wallet};
use App\Payment\Data\{PaymentCreation, ProviderAmount};
use App\Repositories\{DepositInitiationRepository, DepositRepository, LedgerRepository};
use App\Support\Money;

class DepositService
{
    public const SCOPE = 'deposit:create';

    public function __construct(
        private readonly DepositRepository $deposits,
        private readonly DepositInitiationRepository $initiation,
        private readonly LedgerRepository $ledger,
    ) {
    }

    public function validateAmount(mixed $amount): int
    {
        return Money::assertValidOperationAmount($amount);
    }

    /** @throws IdempotencyKeyTaken */
    public function createPending(int $userId, string $key, string $requestHash, Wallet $wallet, int $amount, string $provider, ProviderAmount $providerAmount): Deposit
    {
        return $this->initiation->createPending([
            'user_id' => $userId,
            'scope' => self::SCOPE,
            'key' => $key,
            'request_hash' => $requestHash,
            'wallet_id' => $wallet->id,
            'currency_id' => $wallet->currency_id,
            'provider' => $provider,
            'amount' => $amount,
            'provider_amount' => $providerAmount->amount,
            'provider_currency' => $providerAmount->currency,
        ]);
    }

    public function find(int $id): Deposit
    {
        return $this->deposits->find($id) ?? throw new DepositNotFound();
    }

    public function findForReturn(string $provider, ?string $authority): Deposit
    {
        if ($authority === null) {
            throw new DepositNotFound();
        }

        return $this->deposits->findByAuthority($provider, $authority) ?? throw new DepositNotFound();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function recordAuthority(Deposit $deposit, PaymentCreation $creation): array
    {
        return $this->initiation->recordAuthority($deposit->id, $creation->authority, $creation->redirectUrl);
    }

    public function markVerified(Deposit $deposit, string $providerReference): Deposit
    {
        return $this->deposits->markVerified($deposit->id, $providerReference);
    }

    public function markVerificationUnknown(Deposit $deposit): Deposit
    {
        return $this->deposits->markVerificationUnknown($deposit->id);
    }

    public function markFailed(Deposit $deposit): Deposit
    {
        return $this->deposits->markFailed($deposit->id);
    }

    /** Idempotent: an already confirmed deposit is returned untouched. */
    public function post(Deposit $deposit): Deposit
    {
        $posted = $this->ledger->postDeposit($deposit->id);

        return $this->find($posted->id);
    }

    public function needsVerification(Deposit $deposit): bool
    {
        return in_array($deposit->status, [DepositStatus::Pending, DepositStatus::VerificationUnknown], true);
    }
}

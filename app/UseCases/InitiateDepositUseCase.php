<?php

namespace App\UseCases;

use App\Enums\DepositStatus;
use App\Exceptions\{DepositStateConflict, IdempotencyKeyTaken, PaymentProviderException, ProviderRejected, ProviderUnavailable};
use App\Models\Deposit;
use App\Services\{CurrencyService, DepositService, IdempotencyService, PaymentGatewayService, WalletService};
use App\Support\CanonicalHash;
use Illuminate\Support\Facades\Log;

class InitiateDepositUseCase
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly CurrencyService $currencies,
        private readonly DepositService $deposits,
        private readonly IdempotencyService $idempotency,
        private readonly PaymentGatewayService $gateway,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function execute(int $userId, string $key, int $walletId, mixed $amount, ?string $provider): array
    {
        $provider ??= $this->gateway->defaultProvider();
        $hash = CanonicalHash::of(['wallet_id' => $walletId, 'amount' => $amount, 'provider' => $provider]);

        $stored = $this->idempotency->lookup($userId, DepositService::SCOPE, $key, $hash);

        if ($stored === null) {
            $deposit = $this->createDeposit($userId, $key, $hash, $walletId, $amount, $provider);

            if ($deposit !== null) {
                return $this->callProvider($deposit);
            }

            // Lost the insert race: the winner's record is committed.
            $stored = $this->idempotency->lookupWinner($userId, DepositService::SCOPE, $key, $hash);
        }

        if ($stored['response_status'] !== null) {
            return $this->replay($stored);
        }

        return $this->resume($userId, $key, $hash, $stored['deposit_id']);
    }

    private function createDeposit(int $userId, string $key, string $hash, int $walletId, mixed $amount, string $provider): ?Deposit
    {
        $wallet = $this->wallets->requireOwned($walletId, $userId);
        $this->currencies->assertActive($wallet->currency);
        $amount = $this->deposits->validateAmount($amount);
        $providerAmount = $this->gateway->toProviderAmount($provider, $amount, $wallet->currency->code);

        try {
            return $this->deposits->createPending($userId, $key, $hash, $wallet, $amount, $provider, $providerAmount);
        } catch (IdempotencyKeyTaken) {
            return null;
        }
    }

    /**
     * The provider is called with no transaction open and no lock held.
     *
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function callProvider(Deposit $deposit): array
    {
        if ($deposit->status !== DepositStatus::Pending || $deposit->provider_authority !== null) {
            throw new DepositStateConflict('This deposit can no longer be initiated. Use a new idempotency key.');
        }

        try {
            $creation = $this->gateway->createPayment($deposit);
        } catch (PaymentProviderException $e) {
            Log::warning('Payment creation failed', [
                'deposit' => $deposit->reference,
                'provider' => $deposit->provider,
                'retryable' => $e->retryable,
            ]);

            if ($e->retryable) {
                // Outcome unknown; the same key resumes this deposit.
                throw new ProviderUnavailable();
            }

            $this->deposits->markFailed($deposit);
            throw new ProviderRejected();
        }

        return $this->deposits->recordAuthority($deposit, $creation) + ['replayed' => false];
    }

    /**
     * Same logical request whose initiation has not finished (e.g. a provider timeout, or a
     * concurrent request still talking to the provider): continue with the same deposit.
     *
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function resume(int $userId, string $key, string $hash, int $depositId): array
    {
        $deposit = $this->deposits->find($depositId);

        if ($deposit->provider_authority !== null) {
            // The other request finished meanwhile; its authority and stored response commit together.
            return $this->replay($this->idempotency->lookupWinner($userId, DepositService::SCOPE, $key, $hash));
        }

        return $this->callProvider($deposit);
    }

    /**
     * @param  array{response_status: ?int, response_body: ?array<string, mixed>, deposit_id: ?int}  $stored
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function replay(array $stored): array
    {
        return ['status' => $stored['response_status'], 'body' => $stored['response_body'], 'replayed' => true];
    }
}

<?php

namespace App\Payment\Contracts;

use App\Exceptions\PaymentProviderException;
use App\Payment\Data\{PaymentCreation, PaymentRequest, ProviderAmount, ProviderReturn, VerificationResult, VerifyRequest};

/**
 * Adapters translate between the application and one gateway. They never touch
 * the database, wallets or the ledger.
 */
interface PaymentProvider
{
    public function name(): string;

    /** Converts a canonical amount to the provider's unit; the only place units change. */
    public function toProviderAmount(int $amount, string $currencyCode): ProviderAmount;

    /** @throws PaymentProviderException */
    public function createPayment(PaymentRequest $request): PaymentCreation;

    public function getRedirectUrl(string $authority): string;

    /** @param array<string, mixed> $query */
    public function parseReturn(array $query): ProviderReturn;

    /** Never throws for gateway/network problems; they are normalized into the outcome. */
    public function verifyPayment(VerifyRequest $request): VerificationResult;
}

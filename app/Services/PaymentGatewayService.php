<?php

namespace App\Services;

use App\Enums\VerificationOutcome;
use App\Exceptions\PaymentProviderException;
use App\Models\Deposit;
use App\Payment\Data\{PaymentCreation, PaymentRequest, ProviderAmount, ProviderReturn, VerificationResult, VerifyRequest};
use App\Payment\ProviderRegistry;
use Illuminate\Support\Facades\Log;

/**
 * The only collaborator that talks to payment providers (not a repository: it holds no
 * persistence). Everything returned is normalized; callers never see gateway-specific data.
 */
class PaymentGatewayService
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    public function defaultProvider(): string
    {
        return $this->providers->defaultName();
    }

    public function toProviderAmount(string $provider, int $amount, string $currencyCode): ProviderAmount
    {
        return $this->providers->resolve($provider)->toProviderAmount($amount, $currencyCode);
    }

    /** @throws PaymentProviderException */
    public function createPayment(Deposit $deposit): PaymentCreation
    {
        $provider = $this->providers->resolve($deposit->provider);

        return $provider->createPayment(new PaymentRequest(
            reference: $deposit->reference,
            amount: new ProviderAmount($deposit->provider_amount, $deposit->provider_currency),
            callbackUrl: (string) config("wallet.providers.{$deposit->provider}.callback_url"),
            description: 'Wallet deposit '.$deposit->reference,
        ));
    }

    /** @param array<string, mixed> $query */
    public function parseReturn(string $provider, array $query): ProviderReturn
    {
        return $this->providers->resolve($provider)->parseReturn($query);
    }

    /** Server-to-server verification with the amount persisted at initiation, never a browser value. */
    public function verify(Deposit $deposit): VerificationResult
    {
        $provider = $this->providers->resolve($deposit->provider);

        $result = $provider->verifyPayment(new VerifyRequest(
            authority: $deposit->provider_authority,
            amount: new ProviderAmount($deposit->provider_amount, $deposit->provider_currency),
        ));

        if (! in_array($result->outcome, [VerificationOutcome::Verified, VerificationOutcome::AlreadyVerified], true)) {
            Log::info('Deposit verification not successful', [
                'deposit' => $deposit->reference,
                'provider' => $deposit->provider,
                'outcome' => $result->outcome->value,
                'provider_code' => $result->providerCode,
            ]);
        }

        return $result;
    }
}

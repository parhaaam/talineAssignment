<?php

namespace App\UseCases;

use App\Enums\{DepositStatus, VerificationOutcome};
use App\Models\Deposit;
use App\Payment\Data\ProviderReturn;
use App\Services\{DepositService, PaymentGatewayService};
use Illuminate\Support\Facades\Log;

/**
 * Customer returning from the gateway. The browser parameters are untrusted: they only
 * select which stored deposit to look at; money moves solely after server-to-server
 * verification, and the response is built from persisted state.
 */
class HandleDepositReturnUseCase
{
    public function __construct(
        private readonly PaymentGatewayService $gateway,
        private readonly DepositService $deposits,
    ) {
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function execute(string $provider, array $query): array
    {
        $return = $this->gateway->parseReturn($provider, $query);
        $deposit = $this->deposits->findForReturn($provider, $return->authority);
        [$deposit, $result] = $this->settle($deposit, $return);

        return [
            'result' => $result,
            'deposit_reference' => $deposit->reference,
            'amount' => $deposit->amount,
            'currency' => $deposit->currency->code,
        ];
    }

    /**
     * @return array{0: Deposit, 1: string}
     */
    private function settle(Deposit $deposit, ProviderReturn $return): array
    {
        if ($deposit->status === DepositStatus::Confirmed) {
            return [$deposit, 'confirmed'];
        }

        if ($deposit->status === DepositStatus::Failed) {
            // A failed deposit is never flipped by a later browser visit.
            return [$deposit, 'failed'];
        }

        if (! $return->reportedOk) {
            return [$deposit, 'cancelled'];
        }

        if ($this->deposits->needsVerification($deposit)) {
            $verification = $this->gateway->verify($deposit);

            if ($verification->isSuccessful()) {
                $deposit = $this->deposits->markVerified($deposit, $verification->providerReference);
            } elseif ($verification->outcome === VerificationOutcome::NotVerified) {
                $deposit = $this->deposits->markFailed($deposit);

                return [$deposit, $deposit->status === DepositStatus::Failed ? 'failed' : 'pending'];
            } else {
                if ($verification->outcome === VerificationOutcome::PermanentError) {
                    Log::error('Deposit verification hit a permanent provider error', [
                        'deposit' => $deposit->reference,
                        'provider_code' => $verification->providerCode,
                    ]);
                }
                // Timeout/unknown is never proof of failure: keep the deposit recoverable.
                $deposit = $this->deposits->markVerificationUnknown($deposit);

                return [$deposit, 'pending'];
            }
        }

        // Verified evidence is persisted; posting is retried on every visit until it commits.
        $deposit = $this->deposits->post($deposit);

        return [$deposit, 'confirmed'];
    }
}

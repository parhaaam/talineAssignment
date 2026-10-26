<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\{CurrencyInactive, CurrencyMismatch, DepositNotFound, DepositStateConflict, Forbidden, IdempotencyConflict, InvalidAmount, ProviderDisabled, ProviderRejected, ProviderUnavailable, WalletNotFound};
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDepositRequest;
use App\Http\Support\ControllerExecutor;
use App\UseCases\{HandleDepositReturnUseCase, InitiateDepositUseCase};
use Illuminate\Http\{JsonResponse, Request};

class DepositController extends Controller
{
    public function __construct(
        private readonly InitiateDepositUseCase $initiateDeposit,
        private readonly HandleDepositReturnUseCase $handleReturn,
        private readonly ControllerExecutor $executor,
    ) {
    }

    /**
     * Start a deposit
     *
     * Creates a pending deposit and asks the payment provider for a payment session.
     * Nothing is credited here: the client redirects the customer to `redirect_url`, and the
     * wallet is only credited after the customer returns and the payment is verified
     * server-to-server.
     *
     * Retrying with the same `Idempotency-Key` and body returns the same result (the response
     * carries `Idempotent-Replayed: true`) and never creates a second deposit; the same key with
     * a different body returns 409. A replay always reports the deposit's current `status`, and
     * `redirect_url` is only present while the deposit is still `pending`: a customer who cancelled
     * can pay again through the same link, while a confirmed, failed or unverified deposit gets `null`. After a 503 (provider outcome unknown) retry with the same
     * key to resume the same deposit. After a 502 the deposit is failed and a new key is required.
     *
     * @status 201
     *
     * @response array{deposit_reference: string, status: string, provider: string, amount: int, currency: string, redirect_url: string|null}
     *
     * @throws Forbidden
     * @throws WalletNotFound
     * @throws IdempotencyConflict
     * @throws DepositStateConflict
     * @throws ProviderDisabled
     * @throws InvalidAmount
     * @throws CurrencyInactive
     * @throws CurrencyMismatch
     * @throws ProviderRejected
     * @throws ProviderUnavailable
     */
    public function store(CreateDepositRequest $request): JsonResponse
    {
        return $this->executor->run(function () use ($request) {
            $result = $this->initiateDeposit->execute(
                (int) $request->attributes->get('user_id'),
                $request->idempotencyKey(),
                $request->integer('wallet_id'),
                $request->input('amount'),
                $request->input('provider'),
            );

            return response()->json($result['body'], $result['status'])
                ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    /**
     * Customer return from the payment provider
     *
     * The browser redirect from the gateway. It carries no user header: the query string is
     * untrusted and only selects a stored deposit by its `Authority`. The payment is then verified
     * server-to-server using the amount stored at initiation, and the ledger transaction is posted
     * only after that. Nothing is ever created or credited from the query parameters alone.
     *
     * `result` is derived from persisted state: `confirmed` (the ledger transaction committed),
     * `pending` (verification outcome not established yet; open the same URL again to retry),
     * `cancelled` (the provider reported cancellation, nothing credited) or `failed` (the provider
     * authoritatively rejected the payment). Revisiting the URL never credits twice.
     *
     * @unauthenticated
     *
     * @response array{result: string, deposit_reference: string, amount: int, currency: string}
     *
     * @throws DepositNotFound
     * @throws DepositStateConflict
     * @throws ProviderDisabled
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        return $this->executor->run(
            fn () => response()->json($this->handleReturn->execute($provider, $request->query())),
        );
    }
}

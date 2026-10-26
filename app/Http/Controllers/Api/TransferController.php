<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\{CurrencyInactive, CurrencyMismatch, Forbidden, IdempotencyConflict, InsufficientFunds, InvalidAmount, SelfTransfer, WalletNotFound};
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTransferRequest;
use App\Http\Support\ControllerExecutor;
use App\UseCases\CreateTransferUseCase;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function __construct(
        private readonly CreateTransferUseCase $createTransfer,
        private readonly ControllerExecutor $executor,
    ) {
    }

    /**
     * Create a transfer
     *
     * Moves money between two wallets of the same currency. The debit, the credit, the two
     * ledger entries and the stored idempotency outcome commit atomically, or not at all.
     * Amounts are integers in the currency's storage unit (rials for IRR).
     *
     * Retrying with the same `Idempotency-Key` and the same body returns the original
     * response without moving money again (the response carries `Idempotent-Replayed: true`);
     * the same key with a different body returns 409. Requests that fail validation are not
     * stored, so their key can be reused.
     *
     * @status 201
     *
     * @response array{reference: string, type: string, status: string, amount: int, currency: string, from_wallet_id: int, to_wallet_id: int, posted_at: string}
     *
     * @throws Forbidden
     * @throws WalletNotFound
     * @throws IdempotencyConflict
     * @throws InsufficientFunds
     * @throws SelfTransfer
     * @throws CurrencyMismatch
     * @throws CurrencyInactive
     * @throws InvalidAmount
     */
    public function store(CreateTransferRequest $request): JsonResponse
    {
        return $this->executor->run(function () use ($request) {
            $result = $this->createTransfer->execute(
                (int) $request->attributes->get('user_id'),
                $request->idempotencyKey(),
                $request->integer('from_wallet_id'),
                $request->integer('to_wallet_id'),
                $request->input('amount'),
            );

            return response()->json($result['body'], $result['status'])
                ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\{Forbidden, WalletNotFound};
use App\Http\Controllers\Controller;
use App\Http\Requests\ListTransactionsRequest;
use App\Http\Support\ControllerExecutor;
use App\UseCases\ListWalletTransactionsUseCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class WalletTransactionController extends Controller
{
    public function __construct(
        private readonly ListWalletTransactionsUseCase $listTransactions,
        private readonly ControllerExecutor $executor,
    ) {
    }

    /**
     * List a wallet's transactions
     *
     * Posted transactions of one wallet, newest first (by transaction id), paginated with an opaque
     * cursor: pass the previous response's `next_cursor` as `cursor` to get the next page, until it
     * is `null`. Both wallets of a transfer see the same `reference`; `direction` is from the
     * requested wallet's point of view, and `counterparty_wallet_id` is `null` for deposits.
     * Pending and failed deposits are not transactions and are not listed.
     *
     * Dates are ISO-8601 and normalized to UTC (a value without an offset is read as UTC);
     * `from` is inclusive and `to` is exclusive.
     *
     * @response array{data: list<array{reference: string, type: string, status: string, direction: string, amount: int, currency: string, counterparty_wallet_id: int|null, posted_at: string}>, next_cursor: string|null, per_page: int}
     *
     * @throws Forbidden
     * @throws WalletNotFound
     */
    public function index(ListTransactionsRequest $request, int $wallet): JsonResponse
    {
        return $this->executor->run(function () use ($request, $wallet) {
            $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'))->utc() : null;
            $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to'))->utc() : null;

            return response()->json($this->listTransactions->execute(
                (int) $request->attributes->get('user_id'),
                $wallet,
                $request->input('type'),
                $from,
                $to,
                $request->integer('per_page', (int) config('wallet.history.default_per_page')),
            ));
        });
    }
}

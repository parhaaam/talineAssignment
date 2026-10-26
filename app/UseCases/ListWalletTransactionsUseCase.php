<?php

namespace App\UseCases;

use App\Services\{TransactionHistoryService, WalletService};
use Carbon\CarbonImmutable;

class ListWalletTransactionsUseCase
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionHistoryService $history,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, per_page: int}
     */
    public function execute(int $userId, int $walletId, ?string $type, ?CarbonImmutable $from, ?CarbonImmutable $to, int $perPage): array
    {
        $wallet = $this->wallets->requireOwned($walletId, $userId);

        return $this->history->page($wallet, $type, $from, $to, $perPage);
    }
}

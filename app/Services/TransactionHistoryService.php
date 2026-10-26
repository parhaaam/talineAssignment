<?php

namespace App\Services;

use App\Enums\{TransactionStatus, TransactionType};
use App\Models\Wallet;
use App\Repositories\{AccountRepository, EntryRepository};
use Carbon\CarbonImmutable;

class TransactionHistoryService
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly AccountRepository $accounts,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, per_page: int}
     */
    public function page(Wallet $wallet, ?string $type, ?CarbonImmutable $from, ?CarbonImmutable $to, int $perPage): array
    {
        $accountId = $this->accounts->walletAccountId($wallet->id);

        if ($accountId === null) {
            return ['data' => [], 'next_cursor' => null, 'per_page' => $perPage];
        }

        $page = $this->entries->historyPage(
            $accountId,
            $type === null ? null : TransactionType::fromLabel($type)?->value,
            $from,
            $to,
            $perPage,
        );
        $items = collect($page->items());
        $counterparties = $this->entries->counterparties($items->pluck('id')->all(), $accountId);

        $data = $items->map(function ($row) use ($wallet, $counterparties) {
            $counterparty = $counterparties->get($row->id);

            return [
                'reference' => $row->reference,
                'type' => TransactionType::from($row->type)->label(),
                'status' => TransactionStatus::from($row->status)->label(),
                'direction' => $row->entry_amount > 0 ? 'credit' : 'debit',
                'amount' => (int) $row->amount,
                'currency' => $wallet->currency->code,
                'counterparty_wallet_id' => $counterparty?->wallet_id,
                'posted_at' => CarbonImmutable::parse($row->posted_at)->toIso8601String(),
            ];
        })->values()->all();

        return [
            'data' => $data,
            'next_cursor' => $page->nextCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }
}

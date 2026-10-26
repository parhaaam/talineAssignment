<?php

namespace App\Repositories;

use App\Models\Entry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

class EntryRepository
{
    public function __construct(private readonly Entry $model)
    {
    }

    /**
     * Newest transaction first. Seeks on entries(account_id, transaction_id); the
     * transactions row is reached through its primary key.
     */
    public function historyPage(int $accountId, ?int $type, ?CarbonInterface $from, ?CarbonInterface $to, int $perPage): CursorPaginator
    {
        return $this->model->newQuery()
            ->join('transactions', 'transactions.id', '=', 'entries.transaction_id')
            ->where('entries.account_id', $accountId)
            ->when($type !== null, fn ($q) => $q->where('transactions.type', $type))
            ->when($from !== null, fn ($q) => $q->where('transactions.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('transactions.created_at', '<', $to))
            ->select([
                'entries.transaction_id as id',
                'entries.amount as entry_amount',
                'transactions.reference',
                'transactions.type',
                'transactions.status',
                'transactions.amount',
                'transactions.created_at as posted_at',
            ])
            // Ordering by the alias lets the cursor read the value from each row; it resolves to entries.transaction_id.
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Wallet and account type on the other side of each transaction, in one query.
     *
     * @param  array<int, int>  $transactionIds
     * @return Collection<int, Entry> keyed by transaction id
     */
    public function counterparties(array $transactionIds, int $accountId): Collection
    {
        return $this->model->newQuery()
            ->join('accounts', 'accounts.id', '=', 'entries.account_id')
            ->whereIn('entries.transaction_id', $transactionIds)
            ->where('entries.account_id', '!=', $accountId)
            ->select(['entries.transaction_id', 'accounts.wallet_id', 'accounts.type as account_type'])
            ->get()
            ->keyBy('transaction_id');
    }
}

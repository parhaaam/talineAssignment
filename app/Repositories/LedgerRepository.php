<?php

namespace App\Repositories;

use App\Enums\{AccountType, DepositStatus, TransactionStatus, TransactionType};
use App\Exceptions\{CurrencyInactive, CurrencyMismatch, DepositNotFound, DepositStateConflict, Forbidden, IdempotencyKeyTaken, LedgerImbalance, WalletNotFound};
use App\Models\{Account, Deposit, Entry, IdempotencyRecord, Transaction, Wallet};
use App\Support\{LedgerInvariant, Money};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional repository: every posted financial operation (transaction, entries,
 * wallet balances, and the rows that point at them) commits or rolls back as one unit.
 * No network call may ever be made from inside these transactions.
 */
class LedgerRepository
{
    public function __construct(
        private readonly Transaction $transactions,
        private readonly Entry $entries,
        private readonly Wallet $wallets,
        private readonly Account $accounts,
        private readonly IdempotencyRecord $records,
        private readonly Deposit $deposits,
    ) {
    }

    /**
     * @param  array{user_id: int, scope: string, key: string, request_hash: string, from_wallet_id: int, to_wallet_id: int, amount: int}  $transfer
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws IdempotencyKeyTaken
     */
    public function postTransfer(array $transfer): array
    {
        return DB::transaction(function () use ($transfer) {
            try {
                $record = $this->records->newQuery()->create([
                    'user_id' => $transfer['user_id'],
                    'scope' => $transfer['scope'],
                    'idempotency_key' => $transfer['key'],
                    'request_hash' => $transfer['request_hash'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request holds the key; the caller re-reads its committed outcome.
                throw new IdempotencyKeyTaken();
            }

            $fromId = $transfer['from_wallet_id'];
            $toId = $transfer['to_wallet_id'];
            $amount = $transfer['amount'];

            // Deterministic ascending lock order prevents deadlocks between opposite transfers.
            $locked = $this->wallets->newQuery()->with('currency')
                ->whereIn('id', [$fromId, $toId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locked->count() !== 2) {
                throw new WalletNotFound();
            }

            $sender = $locked[$fromId];
            $recipient = $locked[$toId];

            if ((int) $sender->user_id !== $transfer['user_id']) {
                throw new Forbidden();
            }
            if ($sender->currency_id !== $recipient->currency_id) {
                throw new CurrencyMismatch('Cross-currency transfers are not supported.');
            }
            if (! $sender->currency->is_active) {
                throw new CurrencyInactive();
            }

            $senderBalance = Money::subtract($sender->balance, $amount);
            $recipientBalance = Money::add($recipient->balance, $amount);

            $walletAccounts = $this->accounts->newQuery()
                ->whereIn('wallet_id', [$fromId, $toId])
                ->where('type', AccountType::Wallet->value)
                ->get()
                ->keyBy('wallet_id');

            if ($walletAccounts->count() !== 2
                || $walletAccounts->contains(fn (Account $a) => $a->currency_id !== $sender->currency_id)) {
                throw new LedgerImbalance('Wallet ledger accounts are missing or in the wrong currency.');
            }

            $senderAccountId = $walletAccounts[$fromId]->id;
            $recipientAccountId = $walletAccounts[$toId]->id;

            LedgerInvariant::assertBalanced([$senderAccountId => -$amount, $recipientAccountId => $amount]);

            $transaction = $this->createTransaction(TransactionType::Transfer, $sender->currency_id, $amount);
            $this->entries->newQuery()->create(['transaction_id' => $transaction->id, 'account_id' => $senderAccountId, 'amount' => -$amount]);
            $this->entries->newQuery()->create(['transaction_id' => $transaction->id, 'account_id' => $recipientAccountId, 'amount' => $amount]);

            $this->wallets->newQuery()->whereKey($fromId)->update(['balance' => $senderBalance]);
            $this->wallets->newQuery()->whereKey($toId)->update(['balance' => $recipientBalance]);

            $body = [
                'reference' => $transaction->reference,
                'type' => TransactionType::Transfer->label(),
                'status' => TransactionStatus::Posted->label(),
                'amount' => $amount,
                'currency' => $sender->currency->code,
                'from_wallet_id' => $fromId,
                'to_wallet_id' => $toId,
                'posted_at' => $transaction->posted_at->toIso8601String(),
            ];
            $record->update(['transaction_id' => $transaction->id, 'response_status' => 201, 'response_body' => $body]);

            return ['status' => 201, 'body' => $body];
        }, (int) config('wallet.deadlock_retries'));
    }

    /**
     * Posts a provider-verified deposit exactly once: clearing account debited, wallet
     * account credited, balance increased, deposit confirmed — or nothing at all.
     */
    public function postDeposit(int $depositId): Deposit
    {
        return DB::transaction(function () use ($depositId) {
            $deposit = $this->deposits->newQuery()->with('currency')->lockForUpdate()->find($depositId)
                ?? throw new DepositNotFound();

            if ($deposit->status === DepositStatus::Confirmed) {
                return $deposit;
            }

            // Persisted verification evidence is the only thing that allows posting.
            if ($deposit->status !== DepositStatus::Verified
                || $deposit->transaction_id !== null
                || $deposit->provider_verified_at === null
                || $deposit->provider_reference === null
                || $deposit->provider_authority === null) {
                throw new DepositStateConflict('The deposit has no persisted provider verification.');
            }

            $wallet = $this->wallets->newQuery()->lockForUpdate()->find($deposit->wallet_id)
                ?? throw new WalletNotFound();

            if ($wallet->currency_id !== $deposit->currency_id) {
                throw new CurrencyMismatch('The deposit and wallet currencies differ.');
            }

            $amount = Money::assertValidOperationAmount($deposit->amount);
            $newBalance = Money::add($wallet->balance, $amount);

            $walletAccount = $this->accounts->newQuery()
                ->where('wallet_id', $wallet->id)->where('type', AccountType::Wallet->value)->first();
            $clearing = $this->accounts->newQuery()
                ->where('type', AccountType::PaymentClearing->value)->where('currency_id', $deposit->currency_id)->first();

            if ($walletAccount === null || $clearing === null
                || $walletAccount->currency_id !== $deposit->currency_id) {
                throw new LedgerImbalance('The wallet or payment-clearing account is missing.');
            }

            LedgerInvariant::assertBalanced([$clearing->id => -$amount, $walletAccount->id => $amount]);

            $transaction = $this->createTransaction(TransactionType::Deposit, $deposit->currency_id, $amount);
            $this->entries->newQuery()->create(['transaction_id' => $transaction->id, 'account_id' => $clearing->id, 'amount' => -$amount]);
            $this->entries->newQuery()->create(['transaction_id' => $transaction->id, 'account_id' => $walletAccount->id, 'amount' => $amount]);

            $this->wallets->newQuery()->whereKey($wallet->id)->update(['balance' => $newBalance]);

            $deposit->update([
                'transaction_id' => $transaction->id,
                'status' => DepositStatus::Confirmed,
                'confirmed_at' => now(),
            ]);

            return $deposit;
        }, (int) config('wallet.deadlock_retries'));
    }

    private function createTransaction(TransactionType $type, int $currencyId, int $amount): Transaction
    {
        return $this->transactions->newQuery()->create([
            'reference' => strtolower((string) Str::ulid()),
            'currency_id' => $currencyId,
            'type' => $type->value,
            'status' => TransactionStatus::Posted->value,
            'amount' => $amount,
            'posted_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Concurrency;

use App\Enums\{DepositStatus, TransactionType};
use App\Exceptions\{IdempotencyConflict, InsufficientFunds};
use App\Models\{Deposit, Entry, IdempotencyRecord, Transaction};
use Illuminate\Database\QueryException;

class ConcurrencyTest extends ConcurrencyTestCase
{
    private function transferJob(int $userId, int $from, int $to, int $amount, string $key): array
    {
        return ['action' => 'transfer', 'user_id' => $userId, 'from' => $from, 'to' => $to, 'amount' => $amount, 'key' => $key];
    }

    public function test_concurrent_duplicate_idempotency_keys_create_one_transfer(): void
    {
        $from = $this->makeWallet(balance: 10_000);
        $to = $this->makeWallet();
        $transactionsBefore = Transaction::query()->count();

        $results = $this->runInParallel(array_fill(0, 8, $this->transferJob($from->user_id, $from->id, $to->id, 1_000, 'same-key')));

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
        }
        $references = array_unique(array_map(fn ($r) => $r['result']['body']['reference'], $results));
        $this->assertCount(1, $references, 'every duplicate sees the one original outcome');
        $this->assertSame(1, collect($results)->where('result.replayed', false)->count(), 'exactly one request executed');

        $this->assertSame(1, Transaction::query()->count() - $transactionsBefore);
        $this->assertSame(1, IdempotencyRecord::query()->count());
        $this->assertSame(9_000, $from->refresh()->balance);
        $this->assertSame(1_000, $to->refresh()->balance);
        $this->assertLedgerConsistent();
    }

    public function test_concurrent_requests_with_one_key_but_different_payloads_conflict(): void
    {
        $from = $this->makeWallet(balance: 10_000);
        $to = $this->makeWallet();

        $results = $this->runInParallel([
            $this->transferJob($from->user_id, $from->id, $to->id, 100, 'k'),
            $this->transferJob($from->user_id, $from->id, $to->id, 200, 'k'),
            $this->transferJob($from->user_id, $from->id, $to->id, 300, 'k'),
        ]);

        $winners = collect($results)->where('ok', true);
        $this->assertCount(1, $winners);
        $this->assertSame(2, collect($results)->where('error', IdempotencyConflict::class)->count());
        $this->assertSame(1, IdempotencyRecord::query()->count());
        $this->assertSame(10_000 - $winners->first()['result']['body']['amount'], $from->refresh()->balance);
        $this->assertLedgerConsistent();
    }

    public function test_competing_transfers_cannot_overspend_the_sender(): void
    {
        $from = $this->makeWallet(balance: 100);
        $to = $this->makeWallet();

        $results = $this->runInParallel(array_map(
            fn ($i) => $this->transferJob($from->user_id, $from->id, $to->id, 30, "spend-{$i}"),
            range(1, 8),
        ));

        $succeeded = collect($results)->where('ok', true)->count();
        $this->assertSame(3, $succeeded, json_encode($results));
        $this->assertSame(5, collect($results)->where('error', InsufficientFunds::class)->count());

        $this->assertSame(10, $from->refresh()->balance);
        $this->assertSame(90, $to->refresh()->balance);
        $this->assertSame(3, Transaction::query()->where('type', TransactionType::Transfer->value)->count());
        $this->assertSame(3, IdempotencyRecord::query()->count());
        $this->assertSame(6, Entry::query()->count() - 2);
        $this->assertLedgerConsistent();
    }

    public function test_opposite_direction_transfers_do_not_deadlock(): void
    {
        $a = $this->makeWallet(balance: 100_000);
        $b = $this->makeWallet(balance: 100_000);

        $jobs = [];
        foreach (range(1, 6) as $i) {
            $jobs[] = $this->transferJob($a->user_id, $a->id, $b->id, 100 * $i, "ab-{$i}");
            $jobs[] = $this->transferJob($b->user_id, $b->id, $a->id, 50 * $i, "ba-{$i}");
        }
        $started = microtime(true);

        $results = $this->runInParallel($jobs);

        $this->assertLessThan(30, microtime(true) - $started, 'transfers must not hang on deadlocks');
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
        }
        $net = array_sum(range(1, 6)) * (100 - 50);
        $this->assertSame(100_000 - $net, $a->refresh()->balance);
        $this->assertSame(100_000 + $net, $b->refresh()->balance);
        $this->assertSame(12, Transaction::query()->where('type', TransactionType::Transfer->value)->count());
        $this->assertLedgerConsistent();
    }

    public function test_concurrent_payment_returns_create_one_transaction(): void
    {
        $wallet = $this->makeWallet();
        $deposit = $this->makeDeposit($wallet, 40_000);

        $results = $this->runInParallel(array_fill(0, 6, ['action' => 'callback', 'authority' => $deposit->provider_authority, 'ref' => '31337']));

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
            $this->assertSame('confirmed', $result['result']['result']);
        }
        $deposit->refresh();
        $this->assertSame(DepositStatus::Confirmed, $deposit->status);
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Deposit->value)->count());
        $this->assertSame(40_000, $wallet->refresh()->balance);
        $this->assertSame(1, Deposit::query()->whereNotNull('transaction_id')->count());
        $this->assertLedgerConsistent();
    }

    public function test_concurrent_duplicate_deposit_initiations_create_one_logical_deposit(): void
    {
        $wallet = $this->makeWallet();

        $results = $this->runInParallel(array_fill(0, 5, [
            'action' => 'deposit', 'user_id' => $wallet->user_id, 'key' => 'dep-key', 'wallet_id' => $wallet->id, 'amount' => 20_000,
        ]));

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
        }
        $references = array_unique(array_map(fn ($r) => $r['result']['body']['deposit_reference'], $results));
        $this->assertCount(1, $references);
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(1, IdempotencyRecord::query()->count());
        $this->assertSame(0, $wallet->refresh()->balance);
    }

    public function test_deadlock_retries_are_bounded_on_a_real_connection(): void
    {
        config(['wallet.deadlock_retries' => 3]);
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();

        $attempts = 0;
        Entry::creating(function () use (&$attempts) {
            $attempts++;
            throw new QueryException('mysql', 'insert into entries', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        $this->postJson('/api/transfers', ['from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => 100], $this->headersFor($from, 'dl'))
            ->assertStatus(500);

        $this->assertSame(3, $attempts);
        $this->assertSame(1_000, $from->refresh()->balance);
        $this->assertSame(0, IdempotencyRecord::query()->count());
    }
}

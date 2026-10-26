<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\{Entry, IdempotencyRecord, Transaction, Wallet};
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FinancialTestCase;

class TransferTest extends FinancialTestCase
{
    private function transfer(Wallet $from, Wallet $to, mixed $amount, ?string $key = 'key-1', $as = null)
    {
        return $this->postJson('/api/transfers', [
            'from_wallet_id' => $from->id,
            'to_wallet_id' => $to->id,
            'amount' => $amount,
        ], $this->headersFor($as ?? $from, $key));
    }

    public function test_valid_transfer_debits_and_credits_equally_with_balanced_entries(): void
    {
        $from = $this->makeWallet(balance: 10_000);
        $to = $this->makeWallet();

        $response = $this->transfer($from, $to, 2_500)->assertCreated()
            ->assertJsonPath('amount', 2_500)
            ->assertJsonPath('currency', 'IRR')
            ->assertJsonPath('type', 'transfer')
            ->assertJsonPath('status', 'posted');

        $this->assertSame(7_500, $from->refresh()->balance);
        $this->assertSame(2_500, $to->refresh()->balance);

        $transaction = Transaction::query()->where('reference', $response->json('reference'))->firstOrFail();
        $entries = Entry::query()->where('transaction_id', $transaction->id)->pluck('amount')->sort()->values()->all();
        $this->assertSame([-2_500, 2_500], $entries);
        $this->assertSame(2_500, $transaction->amount);
        $this->assertLedgerConsistent();
    }

    public function test_insufficient_funds_leaves_no_partial_effects(): void
    {
        $from = $this->makeWallet(balance: 100);
        $to = $this->makeWallet();
        $before = Transaction::query()->count();

        $this->transfer($from, $to, 101)->assertStatus(422)->assertJsonPath('error.code', 'insufficient_funds');

        $this->assertSame(100, $from->refresh()->balance);
        $this->assertSame(0, $to->refresh()->balance);
        $this->assertSame($before, Transaction::query()->count());
        $this->assertSame(0, IdempotencyRecord::query()->count());
    }

    public function test_exact_balance_can_be_spent_but_never_goes_negative(): void
    {
        $from = $this->makeWallet(balance: 500);
        $to = $this->makeWallet();

        $this->transfer($from, $to, 500)->assertCreated();
        $this->assertSame(0, $from->refresh()->balance);

        $this->transfer($from, $to, 1, 'key-2')->assertStatus(422)->assertJsonPath('error.code', 'insufficient_funds');
    }

    public function test_database_rejects_negative_balances(): void
    {
        $wallet = $this->makeWallet();

        $this->expectException(QueryException::class);
        Wallet::query()->whereKey($wallet->id)->update(['balance' => -1]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();

        $this->transfer($from, $to, $amount)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(1_000, $from->refresh()->balance);
        $this->assertSame(1, Transaction::query()->count()); // only the funding transaction exists
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'float' => [10.5],
            'string' => ['abc'],
            'numeric string with decimals' => ['10.5'],
            'above int64 (decoded as float)' => [1e19],
            'null' => [null],
        ];
    }

    public function test_amount_above_configured_maximum_is_rejected(): void
    {
        config(['wallet.max_amount' => 1_000]);
        $from = $this->makeWallet(balance: 5_000);
        $to = $this->makeWallet();

        $this->transfer($from, $to, 1_001)->assertStatus(422);
        $this->transfer($from, $to, 1_000)->assertCreated();
    }

    public function test_recipient_balance_overflow_is_rejected_without_wrapping(): void
    {
        config(['wallet.max_amount' => PHP_INT_MAX]);
        $from = $this->makeWallet(balance: 100);
        $to = $this->makeWallet();
        Wallet::query()->whereKey($to->id)->update(['balance' => PHP_INT_MAX - 5]);

        $this->transfer($from, $to, 10)->assertStatus(422)->assertJsonPath('error.code', 'balance_overflow');

        $this->assertSame(100, $from->refresh()->balance);
        $this->assertSame(PHP_INT_MAX - 5, $to->refresh()->balance);
    }

    public function test_amount_near_limit_succeeds(): void
    {
        config(['wallet.max_amount' => PHP_INT_MAX]);
        $from = $this->makeWallet();
        Wallet::query()->whereKey($from->id)->update(['balance' => PHP_INT_MAX]);
        $to = $this->makeWallet();

        $this->transfer($from, $to, PHP_INT_MAX)->assertCreated();
        $this->assertSame(0, $from->refresh()->balance);
        $this->assertSame(PHP_INT_MAX, $to->refresh()->balance);
    }

    public function test_self_transfer_is_rejected(): void
    {
        $wallet = $this->makeWallet(balance: 1_000);

        $this->transfer($wallet, $wallet, 10)->assertStatus(422)->assertJsonPath('error.code', 'self_transfer');
    }

    public function test_cross_currency_transfer_is_rejected(): void
    {
        $this->makeCurrency('USD');
        $user = $this->makeUser();
        $from = $this->makeWallet($user, 1_000);
        $usd = $this->makeWallet($user, 0, 'USD');

        $this->transfer($from, $usd, 10)->assertStatus(422)->assertJsonPath('error.code', 'currency_mismatch');
    }

    public function test_inactive_currency_blocks_new_transfers(): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();
        $from->currency->update(['is_active' => false]);

        $this->transfer($from, $to, 10)->assertStatus(422)->assertJsonPath('error.code', 'currency_inactive');
        $this->assertSame(1_000, $from->refresh()->balance);
    }

    public function test_user_can_hold_wallets_in_different_currencies_but_only_one_per_currency(): void
    {
        $this->makeCurrency('USD');
        $user = $this->makeUser();
        $this->makeWallet($user);
        $this->makeWallet($user, 0, 'USD');

        $this->assertSame(2, Wallet::query()->where('user_id', $user->id)->count());

        $again = $this->makeWallet($user);
        $this->assertSame(2, Wallet::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Wallet::query()->where('user_id', $user->id)->where('currency_id', $again->currency_id)->count());
    }

    public function test_cannot_spend_from_another_users_wallet(): void
    {
        $victim = $this->makeWallet(balance: 1_000);
        $attacker = $this->makeWallet();

        $this->transfer($victim, $attacker, 100, 'key-1', $attacker)->assertForbidden();
        $this->assertSame(1_000, $victim->refresh()->balance);
    }

    public function test_unknown_wallet_returns_not_found(): void
    {
        $from = $this->makeWallet(balance: 100);

        $this->postJson('/api/transfers', ['from_wallet_id' => $from->id, 'to_wallet_id' => 999_999, 'amount' => 10], $this->headersFor($from))
            ->assertNotFound()->assertJsonPath('error.code', 'wallet_not_found');
    }

    public function test_missing_or_unknown_user_is_unauthenticated(): void
    {
        $from = $this->makeWallet(balance: 100);
        $to = $this->makeWallet();
        $payload = ['from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => 10];

        $this->postJson('/api/transfers', $payload, ['Idempotency-Key' => 'k'])->assertUnauthorized();
        $this->postJson('/api/transfers', $payload, ['X-User-Id' => '987654', 'Idempotency-Key' => 'k'])->assertUnauthorized();
        $this->postJson('/api/transfers', $payload, ['X-User-Id' => 'abc', 'Idempotency-Key' => 'k'])->assertUnauthorized();
    }

    public function test_idempotency_key_is_required_and_bounded(): void
    {
        $from = $this->makeWallet(balance: 100);
        $to = $this->makeWallet();

        $this->transfer($from, $to, 10, null)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->transfer($from, $to, 10, str_repeat('a', 129))->assertStatus(422);
        $this->assertSame(100, $from->refresh()->balance);
    }

    public function test_identical_retry_returns_original_outcome_without_moving_money_twice(): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();

        $first = $this->transfer($from, $to, 300)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
        $second = $this->transfer($from, $to, 300)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertEquals($first->json(), $second->json());
        $this->assertSame(700, $from->refresh()->balance);
        $this->assertSame(300, $to->refresh()->balance);
        $this->assertSame(1, IdempotencyRecord::query()->count());
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Transfer->value)->count());
    }

    public function test_replay_survives_a_later_balance_change(): void
    {
        $from = $this->makeWallet(balance: 300);
        $to = $this->makeWallet();

        $first = $this->transfer($from, $to, 300)->assertCreated();
        // Sender is now empty; a naive re-execution would fail with insufficient funds.
        $this->transfer($from, $to, 300)->assertStatus(201)->assertJsonPath('reference', $first->json('reference'));
    }

    public function test_same_key_with_different_payload_is_a_conflict(): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();

        $this->transfer($from, $to, 300)->assertCreated();
        $this->transfer($from, $to, 301)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(700, $from->refresh()->balance);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $a = $this->makeWallet(balance: 1_000);
        $b = $this->makeWallet(balance: 1_000);
        $sink = $this->makeWallet();

        $this->transfer($a, $sink, 100, 'shared-key')->assertCreated();
        $this->transfer($b, $sink, 100, 'shared-key')->assertCreated();

        $this->assertSame(200, $sink->refresh()->balance);
    }

    public function test_failed_validation_is_not_stored_and_the_key_can_be_reused(): void
    {
        $from = $this->makeWallet(balance: 50);
        $to = $this->makeWallet();

        $this->transfer($from, $to, 100, 'retry-me')->assertStatus(422);
        $this->assertSame(0, IdempotencyRecord::query()->count());

        $this->transfer($from, $to, 40, 'retry-me')->assertCreated();
    }

    public function test_database_failure_mid_transfer_rolls_everything_back(): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();
        $transactions = Transaction::query()->count();
        $entries = Entry::query()->count();

        $creating = 0;
        Entry::creating(function () use (&$creating) {
            if (++$creating === 2) {
                throw new \RuntimeException('simulated failure after the first entry');
            }
        });

        $this->transfer($from, $to, 100)->assertStatus(500)->assertJsonPath('error.code', 'server_error');

        $this->assertSame(1_000, $from->refresh()->balance);
        $this->assertSame(0, $to->refresh()->balance);
        $this->assertSame($transactions, Transaction::query()->count());
        $this->assertSame($entries, Entry::query()->count());
        $this->assertSame(0, IdempotencyRecord::query()->count());

        // The client retries with the same key once the fault is gone: it commits exactly once.
        Entry::flushEventListeners();
        Entry::clearBootedModels();
        $this->transfer($from, $to, 100)->assertCreated();
        $this->assertSame(900, $from->refresh()->balance);
        $this->assertLedgerConsistent();
    }

    public function test_transfer_is_visible_in_both_histories_as_one_transaction(): void
    {
        $from = $this->makeWallet(balance: 1_000);
        $to = $this->makeWallet();
        $reference = $this->transfer($from, $to, 100)->json('reference');

        $sender = $this->getJson("/api/wallets/{$from->id}/transactions?type=transfer", $this->headersFor($from, null))->json('data');
        $receiver = $this->getJson("/api/wallets/{$to->id}/transactions", $this->headersFor($to, null))->json('data');

        $this->assertSame($reference, $sender[0]['reference']);
        $this->assertSame($reference, $receiver[0]['reference']);
        $this->assertSame('debit', $sender[0]['direction']);
        $this->assertSame('credit', $receiver[0]['direction']);
        $this->assertSame($to->id, $sender[0]['counterparty_wallet_id']);
        $this->assertSame($from->id, $receiver[0]['counterparty_wallet_id']);
    }
}

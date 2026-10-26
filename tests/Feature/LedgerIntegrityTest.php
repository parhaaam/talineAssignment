<?php

namespace Tests\Feature;

use App\Enums\{AccountType, TransactionStatus, TransactionType};
use App\Models\{Account, Currency, Entry, Transaction, Wallet};
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FinancialTestCase;

class LedgerIntegrityTest extends FinancialTestCase
{
    public function test_posted_entries_and_transactions_are_immutable_at_the_model_level(): void
    {
        $this->fund($this->makeWallet(), 100);

        foreach ([
            fn () => Entry::query()->firstOrFail()->update(['amount' => 5]),
            fn () => Entry::query()->firstOrFail()->delete(),
            fn () => Transaction::query()->firstOrFail()->update(['amount' => 5]),
            fn () => Transaction::query()->firstOrFail()->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('mutation of posted history was allowed');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_database_enforces_ledger_shape_constraints(): void
    {
        $wallet = $this->makeWallet();
        $account = Account::query()->where('wallet_id', $wallet->id)->firstOrFail();

        $tx = fn (int $amount = 10) => Transaction::query()->create([
            'reference' => strtolower((string) Str::ulid()), 'currency_id' => $wallet->currency_id,
            'type' => TransactionType::Transfer->value, 'status' => TransactionStatus::Posted->value, 'amount' => $amount, 'posted_at' => now(),
        ]);

        $this->assertThrowsQuery(fn () => $tx(0));
        $this->assertThrowsQuery(fn () => $tx(-5));

        $transaction = $tx();
        $this->assertThrowsQuery(fn () => Entry::query()->create(['transaction_id' => $transaction->id, 'account_id' => $account->id, 'amount' => 0]));

        Entry::query()->create(['transaction_id' => $transaction->id, 'account_id' => $account->id, 'amount' => 1]);
        $this->assertThrowsQuery(fn () => Entry::query()->create(['transaction_id' => $transaction->id, 'account_id' => $account->id, 'amount' => 2]));
    }

    public function test_each_wallet_has_exactly_one_ledger_account_in_its_currency(): void
    {
        $wallet = $this->makeWallet();
        app(WalletService::class)->provision($wallet->user_id, 'IRR'); // idempotent provisioning

        $accounts = Account::query()->where('wallet_id', $wallet->id)->get();
        $this->assertCount(1, $accounts);
        $this->assertSame($wallet->currency_id, $accounts[0]->currency_id);
        $this->assertSame(AccountType::Wallet, $accounts[0]->type);
        $this->assertThrowsQuery(fn () => Account::query()->create([
            'wallet_id' => $wallet->id, 'currency_id' => $wallet->currency_id, 'code' => 'DUP', 'name' => 'dup', 'type' => AccountType::Wallet->value,
        ]));
        $this->assertThrowsQuery(fn () => Wallet::query()->create(['user_id' => $wallet->user_id, 'currency_id' => $wallet->currency_id]));
    }

    public function test_provisioning_a_currency_is_idempotent(): void
    {
        $this->makeCurrency('USD');
        $this->makeCurrency('USD');

        $this->assertSame(1, Currency::query()->where('code', 'USD')->count());
        $this->assertSame(2, Account::query()->where('type', AccountType::PaymentClearing->value)->count());
    }

    private function assertThrowsQuery(\Closure $action): void
    {
        try {
            DB::transaction($action); // savepoint so the surrounding test transaction stays usable
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('expected the database to reject the statement');
    }
}

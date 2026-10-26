<?php

namespace Tests\Support;

use App\Enums\{AccountType, DepositStatus, TransactionStatus, TransactionType};
use App\Models\{Account, Currency, Deposit, Entry, Transaction, User, Wallet};
use App\Payment\Fake\FakePaymentProvider;
use App\Services\{CurrencyService, WalletService};
use Illuminate\Support\Str;

trait FinancialFixtures
{
    protected function seedCurrencies(): void
    {
        app(CurrencyService::class)->provision('IRR', 'Iranian Rial', 'ریال');
        FakePaymentProvider::reset();
    }

    protected function makeUser(): User
    {
        return User::factory()->create();
    }

    protected function makeWallet(?User $user = null, int $balance = 0, string $currency = 'IRR'): Wallet
    {
        $wallet = app(WalletService::class)->provision(($user ?? $this->makeUser())->id, $currency);

        if ($balance > 0) {
            $this->fund($wallet, $balance);
        }

        return $wallet->refresh()->load('currency');
    }

    protected function makeCurrency(string $code): Currency
    {
        return app(CurrencyService::class)->provision($code, $code.' currency', null);
    }

    /** Funds a wallet through a real balanced deposit-style posting so the ledger stays consistent. */
    protected function fund(Wallet $wallet, int $amount): void
    {
        $clearing = Account::query()->where('type', AccountType::PaymentClearing->value)
            ->where('currency_id', $wallet->currency_id)->firstOrFail();
        $account = Account::query()->where('wallet_id', $wallet->id)->firstOrFail();

        $transaction = Transaction::query()->create([
            'reference' => strtolower((string) Str::ulid()),
            'currency_id' => $wallet->currency_id,
            'type' => TransactionType::Deposit->value,
            'status' => TransactionStatus::Posted->value,
            'amount' => $amount,
            'posted_at' => now(),
        ]);
        Entry::query()->create(['transaction_id' => $transaction->id, 'account_id' => $clearing->id, 'amount' => -$amount]);
        Entry::query()->create(['transaction_id' => $transaction->id, 'account_id' => $account->id, 'amount' => $amount]);
        Wallet::query()->whereKey($wallet->id)->update(['balance' => $wallet->balance + $amount]);
    }

    /** @return array<string, string> */
    protected function headersFor(User|Wallet $owner, ?string $key = 'key-1'): array
    {
        $userId = $owner instanceof Wallet ? $owner->user_id : $owner->id;
        $headers = ['X-User-Id' => (string) $userId];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $headers;
    }

    protected function makeDeposit(Wallet $wallet, int $amount = 50_000, DepositStatus $status = DepositStatus::Pending, string $authority = 'FAKE0000000001'): Deposit
    {
        return Deposit::query()->create([
            'wallet_id' => $wallet->id,
            'currency_id' => $wallet->currency_id,
            'reference' => 'DEP-'.Str::ulid(),
            'provider' => 'fake',
            'provider_authority' => $authority,
            'amount' => $amount,
            'provider_amount' => intdiv($amount, 10),
            'provider_currency' => 'IRT',
            'status' => $status,
        ]);
    }

    /** Asserts every posted transaction is balanced, single-currency, and wallet balances equal the ledger. */
    protected function assertLedgerConsistent(): void
    {
        foreach (Transaction::query()->get() as $transaction) {
            $entries = Entry::query()->where('transaction_id', $transaction->id)->get();
            $this->assertGreaterThanOrEqual(2, $entries->count(), "tx {$transaction->id} has fewer than 2 entries");
            $this->assertSame(0, (int) $entries->sum('amount'), "tx {$transaction->id} is unbalanced");
            $this->assertSame(
                [$transaction->currency_id],
                Account::query()->whereIn('id', $entries->pluck('account_id'))->pluck('currency_id')->unique()->values()->all(),
            );
        }

        foreach (Wallet::query()->get() as $wallet) {
            $account = Account::query()->where('wallet_id', $wallet->id)->firstOrFail();
            $this->assertSame((int) Entry::query()->where('account_id', $account->id)->sum('amount'), $wallet->balance, "wallet {$wallet->id} balance drifted from ledger");
        }
    }
}

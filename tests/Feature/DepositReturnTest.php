<?php

namespace Tests\Feature;

use App\Enums\{DepositStatus, TransactionType, VerificationOutcome};
use App\Exceptions\{CurrencyMismatch, DepositStateConflict};
use App\Models\{Deposit, Entry, Transaction};
use App\Payment\Data\VerificationResult;
use App\Payment\Fake\FakePaymentProvider;
use App\Repositories\{DepositRepository, LedgerRepository};
use Tests\FinancialTestCase;

class DepositReturnTest extends FinancialTestCase
{
    private function verified(string $ref = '999111'): VerificationResult
    {
        return new VerificationResult(VerificationOutcome::Verified, $ref, '100');
    }

    private function customerReturns(string $authority = 'FAKE0000000001', string $status = 'OK', string $provider = 'fake')
    {
        return $this->getJson("/api/deposits/callback/{$provider}?Authority={$authority}&Status={$status}");
    }

    private function pendingDeposit(int $amount = 50_000)
    {
        $wallet = $this->makeWallet();

        return [$wallet, $this->makeDeposit($wallet, $amount)];
    }

    public function test_status_ok_alone_never_credits_when_verification_is_unknown(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$defaultVerify = new VerificationResult(VerificationOutcome::Pending);

        $this->customerReturns()->assertOk()->assertJsonPath('result', 'pending');

        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(DepositStatus::VerificationUnknown, $deposit->refresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertCount(1, FakePaymentProvider::$verifyCalls);
    }

    public function test_verified_return_posts_exactly_one_balanced_transaction(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit(50_000);
        FakePaymentProvider::$verifyQueue = [$this->verified('555')];

        $this->customerReturns()->assertOk()
            ->assertJsonPath('result', 'confirmed')
            ->assertJsonPath('amount', 50_000)
            ->assertJsonPath('deposit_reference', $deposit->reference);

        $deposit->refresh();
        $this->assertSame(DepositStatus::Confirmed, $deposit->status);
        $this->assertSame('555', $deposit->provider_reference);
        $this->assertNotNull($deposit->provider_verified_at);
        $this->assertNotNull($deposit->confirmed_at);
        $this->assertSame(50_000, $wallet->refresh()->balance);

        $transaction = Transaction::query()->findOrFail($deposit->transaction_id);
        $this->assertSame(TransactionType::Deposit, $transaction->type);
        $this->assertSame(50_000, $transaction->amount);
        $this->assertSame([-50_000, 50_000], Entry::query()->where('transaction_id', $transaction->id)->pluck('amount')->sort()->values()->all());
        $this->assertLedgerConsistent();
    }

    public function test_verification_uses_the_stored_authority_and_amount_not_browser_values(): void
    {
        [, $deposit] = $this->pendingDeposit(50_000);
        FakePaymentProvider::$verifyQueue = [$this->verified()];

        $this->getJson('/api/deposits/callback/fake?Authority=FAKE0000000001&Status=OK&amount=999999999&wallet_id=1')->assertOk();

        $call = FakePaymentProvider::$verifyCalls[0];
        $this->assertSame('FAKE0000000001', $call->authority);
        $this->assertSame(5_000, $call->amount->amount);
        $this->assertSame('IRT', $call->amount->currency);
        $this->assertSame(50_000, $deposit->refresh()->amount);
    }

    public function test_cancelled_return_credits_nothing_and_does_not_call_the_provider(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();

        $this->customerReturns(status: 'NOK')->assertOk()->assertJsonPath('result', 'cancelled');

        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(DepositStatus::Pending, $deposit->refresh()->status);
        $this->assertSame([], FakePaymentProvider::$verifyCalls);
    }

    public function test_revisiting_the_callback_never_confirms_a_cancelled_payment(): void
    {
        [$wallet] = $this->pendingDeposit();

        $this->customerReturns(status: 'NOK');
        $this->customerReturns(status: 'NOK');

        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_unknown_missing_or_malformed_authorities_cannot_create_or_credit(): void
    {
        [$wallet] = $this->pendingDeposit();

        $this->customerReturns('NOSUCHAUTHORITY')->assertNotFound()->assertJsonPath('error.code', 'deposit_not_found');
        $this->getJson('/api/deposits/callback/fake?Status=OK')->assertNotFound();
        $this->getJson('/api/deposits/callback/fake?Authority[]=x&Status=OK')->assertNotFound();
        $this->customerReturns('FAKE0000000001; DROP TABLE')->assertNotFound();

        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame([], FakePaymentProvider::$verifyCalls);
    }

    public function test_an_authority_from_another_provider_cannot_be_used(): void
    {
        $this->pendingDeposit();

        $this->customerReturns('FAKE0000000001', 'OK', 'zarinpal')->assertNotFound();
        $this->assertSame([], FakePaymentProvider::$verifyCalls);
    }

    public function test_disabled_or_unknown_provider_returns_are_rejected(): void
    {
        $this->pendingDeposit();

        $this->customerReturns('FAKE0000000001', 'OK', 'nope')->assertStatus(422)->assertJsonPath('error.code', 'provider_disabled');
    }

    public function test_authoritative_rejection_fails_the_deposit_without_credit(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$verifyQueue = [new VerificationResult(VerificationOutcome::NotVerified, null, '-51')];

        $this->customerReturns()->assertOk()->assertJsonPath('result', 'failed');

        $deposit->refresh();
        $this->assertSame(DepositStatus::Failed, $deposit->status);
        $this->assertNotNull($deposit->failed_at);
        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_a_failed_deposit_is_not_flipped_by_a_later_success_report(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$verifyQueue = [new VerificationResult(VerificationOutcome::NotVerified, null, '-51')];
        $this->customerReturns();

        FakePaymentProvider::$defaultVerify = $this->verified();
        $this->customerReturns()->assertOk()->assertJsonPath('result', 'failed');

        $this->assertSame(DepositStatus::Failed, $deposit->refresh()->status);
        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertCount(1, FakePaymentProvider::$verifyCalls);
    }

    public function test_retryable_or_permanent_provider_errors_never_fail_the_deposit(): void
    {
        foreach ([VerificationOutcome::RetryableError, VerificationOutcome::PermanentError, VerificationOutcome::Pending] as $i => $outcome) {
            FakePaymentProvider::reset();
            [$wallet, $deposit] = $this->pendingDeposit();
            $deposit->update(['provider_authority' => 'AUTH'.$i]);
            FakePaymentProvider::$verifyQueue = [new VerificationResult($outcome)];

            $this->customerReturns('AUTH'.$i)->assertOk()->assertJsonPath('result', 'pending');

            $this->assertSame(DepositStatus::VerificationUnknown, $deposit->refresh()->status, $outcome->value);
            $this->assertSame(0, $wallet->refresh()->balance);
        }
    }

    public function test_timeout_then_retry_recovers_the_same_deposit_and_credits_once(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$verifyQueue = [new VerificationResult(VerificationOutcome::RetryableError), $this->verified()];

        $this->customerReturns()->assertJsonPath('result', 'pending');
        $this->assertSame(0, $wallet->refresh()->balance);

        $this->customerReturns()->assertJsonPath('result', 'confirmed');
        $this->assertSame(50_000, $wallet->refresh()->balance);
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(DepositStatus::Confirmed, $deposit->refresh()->status);
    }

    public function test_already_verified_response_recovers_the_stored_deposit(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        // The provider verified an earlier attempt whose result we never persisted.
        FakePaymentProvider::$verifyQueue = [new VerificationResult(VerificationOutcome::AlreadyVerified, '777', '101')];

        $this->customerReturns()->assertJsonPath('result', 'confirmed');

        $this->assertSame('777', $deposit->refresh()->provider_reference);
        $this->assertSame(50_000, $wallet->refresh()->balance);
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Deposit->value)->count());
    }

    public function test_success_without_a_provider_reference_is_not_trusted(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$verifyQueue = [new VerificationResult(VerificationOutcome::Verified, null, '100')];

        $this->customerReturns()->assertJsonPath('result', 'pending');

        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(DepositStatus::VerificationUnknown, $deposit->refresh()->status);
    }

    public function test_duplicate_returns_do_not_double_credit(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$defaultVerify = $this->verified();

        foreach (range(1, 4) as $_) {
            $this->customerReturns()->assertOk()->assertJsonPath('result', 'confirmed');
        }

        $this->assertSame(50_000, $wallet->refresh()->balance);
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Deposit->value)->count());
        $this->assertCount(1, FakePaymentProvider::$verifyCalls); // a confirmed deposit is never re-verified
        $this->assertLedgerConsistent();
    }

    public function test_local_posting_failure_leaves_no_partial_state_and_is_recoverable(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$defaultVerify = $this->verified('888');

        $creating = 0;
        Entry::creating(function () use (&$creating) {
            if (++$creating === 2) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->customerReturns()->assertStatus(500);

        // Nothing posted, but the provider verification evidence survived on its own commit.
        $deposit->refresh();
        $this->assertSame(DepositStatus::Verified, $deposit->status);
        $this->assertSame('888', $deposit->provider_reference);
        $this->assertNotNull($deposit->provider_verified_at);
        $this->assertNull($deposit->transaction_id);
        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, Entry::query()->count());

        Entry::flushEventListeners();
        Entry::clearBootedModels();
        $verifyCallsBefore = count(FakePaymentProvider::$verifyCalls);

        // Recovery: no second payment, no second provider call, exactly one credit.
        $this->customerReturns()->assertOk()->assertJsonPath('result', 'confirmed');
        $this->customerReturns()->assertOk()->assertJsonPath('result', 'confirmed');

        $this->assertCount($verifyCallsBefore, FakePaymentProvider::$verifyCalls);
        $this->assertSame(50_000, $wallet->refresh()->balance);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, Deposit::query()->count());
        $this->assertLedgerConsistent();
    }

    public function test_a_verified_but_unposted_deposit_does_not_change_the_balance(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        $deposit->update(['status' => DepositStatus::Verified, 'provider_reference' => '1', 'provider_verified_at' => now()]);

        $this->assertSame(0, $wallet->refresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_posting_requires_persisted_verification_evidence(): void
    {
        [, $deposit] = $this->pendingDeposit();

        $this->expectException(DepositStateConflict::class);
        app(LedgerRepository::class)->postDeposit($deposit->id);
    }

    public function test_deposit_currency_must_match_the_wallet_currency(): void
    {
        [, $deposit] = $this->pendingDeposit();
        $usd = $this->makeCurrency('USD');
        $deposit->update(['status' => DepositStatus::Verified, 'provider_reference' => '1', 'provider_verified_at' => now(), 'currency_id' => $usd->id]);

        $this->expectException(CurrencyMismatch::class);
        app(LedgerRepository::class)->postDeposit($deposit->id);
    }

    public function test_conflicting_provider_reference_is_flagged_not_overwritten(): void
    {
        [, $deposit] = $this->pendingDeposit();
        FakePaymentProvider::$defaultVerify = $this->verified('AAA');
        $deposit->update(['status' => DepositStatus::Verified, 'provider_reference' => 'BBB', 'provider_verified_at' => now()]);

        // Already verified locally: no re-verification, and posting keeps the original reference.
        $this->customerReturns()->assertJsonPath('result', 'confirmed');
        $this->assertSame('BBB', $deposit->refresh()->provider_reference);
    }

    public function test_failed_then_provider_says_verified_is_a_conflict_not_a_silent_flip(): void
    {
        [$wallet, $deposit] = $this->pendingDeposit();
        $deposit->update(['status' => DepositStatus::VerificationUnknown]);
        $service = app(DepositRepository::class);
        $service->markFailed($deposit->id);

        $this->expectException(DepositStateConflict::class);
        try {
            $service->markVerified($deposit->id, 'X');
        } finally {
            $this->assertSame(DepositStatus::Failed, $deposit->refresh()->status);
            $this->assertSame(0, $wallet->refresh()->balance);
        }
    }

    public function test_pending_failed_deposits_have_no_ledger_effect_and_other_deposits_are_isolated(): void
    {
        [$wallet, $one] = $this->pendingDeposit();
        $two = $this->makeDeposit($wallet, 20_000, authority: 'FAKE0000000002');
        FakePaymentProvider::$verifyQueue = [$this->verified('r1')];

        $this->customerReturns('FAKE0000000001')->assertJsonPath('result', 'confirmed');

        $this->assertSame(50_000, $wallet->refresh()->balance);
        $this->assertSame(DepositStatus::Pending, $two->refresh()->status);
    }
}

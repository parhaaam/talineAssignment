<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\{Deposit, IdempotencyRecord, Transaction, Wallet};
use App\Payment\Fake\FakePaymentProvider;
use Tests\FinancialTestCase;

class DepositInitiationTest extends FinancialTestCase
{
    private function initiate(Wallet $wallet, mixed $amount = 50_000, ?string $key = 'dep-1', array $extra = [])
    {
        return $this->postJson('/api/deposits', ['wallet_id' => $wallet->id, 'amount' => $amount] + $extra, $this->headersFor($wallet, $key));
    }

    public function test_initiation_creates_a_pending_deposit_and_returns_the_redirect_url(): void
    {
        $wallet = $this->makeWallet();

        $response = $this->initiate($wallet)->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provider', 'fake')
            ->assertJsonPath('amount', 50_000)
            ->assertJsonPath('currency', 'IRR');

        $deposit = Deposit::query()->where('reference', $response->json('deposit_reference'))->firstOrFail();
        $this->assertSame(DepositStatus::Pending, $deposit->status);
        $this->assertSame($wallet->id, $deposit->wallet_id);
        $this->assertSame('FAKE0000000001', $deposit->provider_authority);
        $this->assertSame('https://fake-gateway.test/pay/FAKE0000000001', $response->json('redirect_url'));
        $this->assertNull($deposit->transaction_id);
        $this->assertNull($deposit->provider_verified_at);
    }

    public function test_provider_receives_the_converted_amount_and_stored_amounts_are_kept_for_verification(): void
    {
        $wallet = $this->makeWallet();
        $this->initiate($wallet, 50_000)->assertCreated();

        $deposit = Deposit::query()->firstOrFail();
        $this->assertSame(50_000, $deposit->amount);          // canonical rials
        $this->assertSame(5_000, $deposit->provider_amount);  // fake provider works in tomans
        $this->assertSame('IRT', $deposit->provider_currency);
        $this->assertSame(5_000, FakePaymentProvider::$createCalls[0]->amount->amount);
        $this->assertStringStartsWith('DEP-', FakePaymentProvider::$createCalls[0]->reference);
    }

    public function test_initiation_never_changes_balances_or_posts_transactions(): void
    {
        $wallet = $this->makeWallet(balance: 1_000);
        $before = Transaction::query()->count();

        $this->initiate($wallet)->assertCreated();

        $this->assertSame(1_000, $wallet->refresh()->balance);
        $this->assertSame($before, Transaction::query()->count());
    }

    public function test_the_local_deposit_exists_before_the_provider_is_called(): void
    {
        $wallet = $this->makeWallet();
        $seenAtCall = null;
        FakePaymentProvider::$createFailure = null;

        // The provider is only reached after the deposit is committed: simulate a hard failure there.
        FakePaymentProvider::$createFailure = new PaymentProviderException('boom', true);
        $this->initiate($wallet)->assertStatus(503);

        $seenAtCall = Deposit::query()->count();
        $this->assertSame(1, $seenAtCall);
        $this->assertSame(1, count(FakePaymentProvider::$createCalls));
    }

    public function test_matching_retry_returns_the_original_result(): void
    {
        $wallet = $this->makeWallet();

        $first = $this->initiate($wallet)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
        $second = $this->initiate($wallet)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertEquals($first->json(), $second->json());
        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame(1, count(FakePaymentProvider::$createCalls));
        $this->assertSame(1, IdempotencyRecord::query()->count());
    }

    public function test_reused_key_with_a_different_payload_conflicts(): void
    {
        $wallet = $this->makeWallet();
        $this->initiate($wallet, 50_000)->assertCreated();

        $this->initiate($wallet, 60_000)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, Deposit::query()->count());
    }

    public function test_provider_timeout_keeps_one_deposit_and_the_retry_resumes_it(): void
    {
        $wallet = $this->makeWallet();
        FakePaymentProvider::$createFailure = new PaymentProviderException('timeout', true);

        $this->initiate($wallet)->assertStatus(503)->assertJsonPath('error.code', 'provider_unavailable');

        $deposit = Deposit::query()->firstOrFail();
        $this->assertSame(DepositStatus::Pending, $deposit->status);
        $this->assertNull($deposit->provider_authority);

        FakePaymentProvider::$createFailure = null;
        $retry = $this->initiate($wallet)->assertCreated();

        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame($deposit->reference, $retry->json('deposit_reference'));
        $this->assertNotNull($deposit->refresh()->provider_authority);
        $this->assertSame(0, $wallet->refresh()->balance);
    }

    public function test_authoritative_provider_rejection_fails_the_deposit_without_crediting(): void
    {
        $wallet = $this->makeWallet();
        FakePaymentProvider::$createFailure = new PaymentProviderException('merchant invalid', false);

        $this->initiate($wallet)->assertStatus(502)->assertJsonPath('error.code', 'provider_rejected');

        $this->assertSame(DepositStatus::Failed, Deposit::query()->firstOrFail()->status);
        $this->assertSame(0, $wallet->refresh()->balance);

        // The same key cannot silently resurrect a failed deposit.
        FakePaymentProvider::$createFailure = null;
        $this->initiate($wallet)->assertStatus(409)->assertJsonPath('error.code', 'deposit_state_conflict');
    }

    public function test_cannot_deposit_into_someone_elses_wallet(): void
    {
        $owner = $this->makeWallet();
        $stranger = $this->makeUser();

        $this->postJson('/api/deposits', ['wallet_id' => $owner->id, 'amount' => 1_000], $this->headersFor($stranger, 'k'))->assertForbidden();
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_unknown_wallet_is_not_found(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/deposits', ['wallet_id' => 424_242, 'amount' => 1_000], $this->headersFor($user, 'k'))->assertNotFound();
    }

    public function test_unknown_or_disabled_providers_are_rejected(): void
    {
        $wallet = $this->makeWallet();

        $this->initiate($wallet, 1_000, 'a', ['provider' => 'paypal'])->assertStatus(422)->assertJsonPath('error.code', 'provider_disabled');
        config(['wallet.providers.fake.enabled' => false]);
        $this->initiate($wallet, 1_000, 'b', ['provider' => 'fake'])->assertStatus(422)->assertJsonPath('error.code', 'provider_disabled');
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_clients_cannot_name_arbitrary_provider_classes(): void
    {
        $wallet = $this->makeWallet();

        $this->initiate($wallet, 1_000, 'a', ['provider' => FakePaymentProvider::class])->assertStatus(422);
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_amounts_not_representable_for_the_provider_are_rejected_before_anything_is_stored(): void
    {
        $wallet = $this->makeWallet();

        $this->initiate($wallet, 1_005)->assertStatus(422)->assertJsonPath('error.code', 'invalid_amount');
        $this->assertSame(0, Deposit::query()->count());
        $this->assertSame(0, IdempotencyRecord::query()->count());
        $this->assertSame([], FakePaymentProvider::$createCalls);
    }

    public function test_invalid_amounts_and_missing_key_fail_validation(): void
    {
        $wallet = $this->makeWallet();

        foreach ([0, -10, 10.5, 'x', null, 1e19] as $i => $amount) {
            $this->initiate($wallet, $amount, "bad-{$i}")->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->initiate($wallet, 1_000, null)->assertStatus(422);
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_excessive_amount_is_rejected(): void
    {
        config(['wallet.max_amount' => 10_000]);
        $wallet = $this->makeWallet();

        $this->initiate($wallet, 10_010)->assertStatus(422);
        $this->initiate($wallet, 10_000, 'ok')->assertCreated();
    }

    public function test_inactive_currency_blocks_new_deposits(): void
    {
        $wallet = $this->makeWallet();
        $wallet->currency->update(['is_active' => false]);

        $this->initiate($wallet)->assertStatus(422)->assertJsonPath('error.code', 'currency_inactive');
    }

    public function test_response_never_exposes_credentials(): void
    {
        $wallet = $this->makeWallet();

        $body = $this->initiate($wallet)->assertCreated()->getContent();

        $this->assertStringNotContainsString((string) config('wallet.providers.zarinpal.merchant_id'), $body);
    }
}

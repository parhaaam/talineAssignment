<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Models\{Deposit, Transaction};
use Illuminate\Http\Client\{ConnectionException, Request};
use Illuminate\Support\Facades\{Http, Log};
use Monolog\Handler\TestHandler;
use Tests\FinancialTestCase;

/** End-to-end through the real ZarinPal adapter with mocked HTTP; no live requests are made. */
class ZarinPalDepositFlowTest extends FinancialTestCase
{
    private const MERCHANT = '00000000-0000-0000-0000-000000000000';

    /** @var array{request: list<mixed>, verify: list<mixed>} */
    private array $queue = ['request' => [], 'verify' => []];

    protected function setUp(): void
    {
        parent::setUp();

        // One stub for the whole test; each test queues the responses it expects (body, [body, status] or an exception).
        Http::fake([
            '*/request.json' => fn () => $this->nextResponse('request'),
            '*/verify.json' => fn () => $this->nextResponse('verify'),
        ]);
        Http::preventStrayRequests();
    }

    private function nextResponse(string $endpoint)
    {
        $item = array_shift($this->queue[$endpoint]) ?? throw new \RuntimeException("No stubbed {$endpoint} response queued.");

        if ($item instanceof \Throwable) {
            throw $item;
        }

        [$body, $status] = isset($item[1]) && is_int($item[1]) && array_key_exists(0, $item) ? $item : [$item, 200];

        return Http::response($body, $status);
    }

    private function queue(string $endpoint, mixed ...$responses): void
    {
        $this->queue[$endpoint] = $responses;
    }

    private function requestOk(string $authority = 'A00000000000000000000000000000000001'): array
    {
        return ['data' => ['code' => 100, 'message' => 'Success', 'authority' => $authority, 'fee_type' => 'Merchant', 'fee' => 100], 'errors' => []];
    }

    private function verifyOk(int $code = 100, int $ref = 123456789): array
    {
        return ['data' => ['code' => $code, 'message' => 'Verified', 'card_hash' => 'x', 'card_pan' => '502229******5995', 'ref_id' => $ref, 'fee_type' => 'Merchant', 'fee' => 0], 'errors' => []];
    }

    private function initiate($wallet, int $amount = 50_000, string $key = 'z-1')
    {
        return $this->postJson('/api/deposits', ['wallet_id' => $wallet->id, 'amount' => $amount, 'provider' => 'zarinpal'], $this->headersFor($wallet, $key));
    }

    public function test_payment_request_uses_current_v4_contract_and_rial_amounts(): void
    {
        $this->queue('request', $this->requestOk());
        $wallet = $this->makeWallet();

        $response = $this->initiate($wallet, 50_000)->assertCreated();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://payment.zarinpal.com/pg/v4/payment/request.json'
                && $request['merchant_id'] === self::MERCHANT
                && $request['amount'] === 50_000
                && $request['currency'] === 'IRR'
                && $request['callback_url'] === 'http://localhost/api/deposits/callback/zarinpal'
                && str_starts_with($request['description'], 'Wallet deposit DEP-')
                && str_starts_with($request['metadata']['order_id'], 'DEP-');
        });

        $deposit = Deposit::query()->firstOrFail();
        $this->assertSame('A00000000000000000000000000000000001', $deposit->provider_authority);
        $this->assertSame(50_000, $deposit->provider_amount);
        $this->assertSame('IRR', $deposit->provider_currency);
        $this->assertSame('https://payment.zarinpal.com/pg/StartPay/A00000000000000000000000000000000001', $response->json('redirect_url'));
        $this->assertSame(DepositStatus::Pending, $deposit->status);
    }

    public function test_sandbox_mode_switches_hosts(): void
    {
        config(['wallet.providers.zarinpal.sandbox' => true]);
        $this->queue('request', $this->requestOk());

        $this->initiate($this->makeWallet())->assertCreated()
            ->assertJsonPath('redirect_url', 'https://sandbox.zarinpal.com/pg/StartPay/A00000000000000000000000000000000001');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'));
    }

    public function test_toman_mode_converts_explicitly_at_the_provider_boundary(): void
    {
        config(['wallet.providers.zarinpal.currency' => 'IRT']);
        $this->queue('request', $this->requestOk());

        $this->initiate($this->makeWallet(), 50_000)->assertCreated();

        Http::assertSent(fn (Request $r) => $r['amount'] === 5_000 && $r['currency'] === 'IRT');
        $deposit = Deposit::query()->firstOrFail();
        $this->assertSame(50_000, $deposit->amount);
        $this->assertSame(5_000, $deposit->provider_amount);

        $this->initiate($this->makeWallet(), 50_005, 'z-2')->assertStatus(422)->assertJsonPath('error.code', 'invalid_amount');
    }

    public function test_full_flow_verifies_server_to_server_with_the_original_amount_then_posts(): void
    {
        $this->queue('request', $this->requestOk());
        $this->queue('verify', $this->verifyOk());
        $wallet = $this->makeWallet();
        $this->initiate($wallet, 75_000)->assertCreated();

        $this->getJson('/api/deposits/callback/zarinpal?Authority=A00000000000000000000000000000000001&Status=OK')
            ->assertOk()->assertJsonPath('result', 'confirmed');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://payment.zarinpal.com/pg/v4/payment/verify.json'
            && $r->data() === ['merchant_id' => self::MERCHANT, 'amount' => 75_000, 'authority' => 'A00000000000000000000000000000000001']);

        $deposit = Deposit::query()->firstOrFail();
        $this->assertSame('123456789', $deposit->provider_reference);
        $this->assertSame(DepositStatus::Confirmed, $deposit->status);
        $this->assertSame(75_000, $wallet->refresh()->balance);
        $this->assertLedgerConsistent();
    }

    public function test_http_200_with_an_error_body_or_malformed_body_is_not_verification(): void
    {
        $bodies = [
            'rejected' => ['data' => [], 'errors' => ['code' => -51, 'message' => 'Session is not valid, session is not active paid try.']],
            'malformed' => ['unexpected' => true],
            'code without ref' => ['data' => ['code' => 100], 'errors' => []],
            'other success-like code' => ['data' => ['code' => 102, 'ref_id' => 5], 'errors' => []],
        ];

        foreach ($bodies as $name => $body) {
            $wallet = $this->makeWallet();
            $authority = 'AUTH'.md5($name);
            $this->makeDeposit($wallet, 10_000, authority: $authority)->update(['provider' => 'zarinpal', 'provider_amount' => 10_000, 'provider_currency' => 'IRR']);
            $this->queue('verify', $body);

            $this->getJson("/api/deposits/callback/zarinpal?Authority={$authority}&Status=OK")->assertOk();

            $this->assertSame(0, $wallet->refresh()->balance, $name);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_authoritative_rejection_marks_failed_but_ambiguous_codes_do_not(): void
    {
        $cases = ['-51' => ['failed', DepositStatus::Failed], '-54' => ['failed', DepositStatus::Failed], '-50' => ['pending', DepositStatus::VerificationUnknown], '-52' => ['pending', DepositStatus::VerificationUnknown]];

        foreach ($cases as $code => [$result, $status]) {
            $wallet = $this->makeWallet();
            $authority = 'AUTHC'.abs((int) $code);
            $deposit = $this->makeDeposit($wallet, 10_000, authority: $authority);
            $deposit->update(['provider' => 'zarinpal', 'provider_amount' => 10_000, 'provider_currency' => 'IRR']);
            $this->queue('verify', ['data' => [], 'errors' => ['code' => (int) $code, 'message' => 'x']]);

            $this->getJson("/api/deposits/callback/zarinpal?Authority={$authority}&Status=OK")->assertJsonPath('result', $result);

            $this->assertSame($status, $deposit->refresh()->status, "code {$code}");
            $this->assertSame(0, $wallet->refresh()->balance);
        }
    }

    public function test_verification_timeout_keeps_the_deposit_recoverable_and_a_retry_succeeds(): void
    {
        $wallet = $this->makeWallet();
        $deposit = $this->makeDeposit($wallet, 10_000, authority: 'AUTHTIMEOUT');
        $deposit->update(['provider' => 'zarinpal', 'provider_amount' => 10_000, 'provider_currency' => 'IRR']);

        $this->queue('verify', new ConnectionException('cURL error 28: Operation timed out'));
        $this->getJson('/api/deposits/callback/zarinpal?Authority=AUTHTIMEOUT&Status=OK')->assertOk()->assertJsonPath('result', 'pending');
        $this->assertSame(DepositStatus::VerificationUnknown, $deposit->refresh()->status);
        $this->assertSame(0, $wallet->refresh()->balance);

        $this->queue('verify', [['data' => [], 'errors' => []], 503]);
        $this->getJson('/api/deposits/callback/zarinpal?Authority=AUTHTIMEOUT&Status=OK')->assertJsonPath('result', 'pending');

        // The payment actually went through: ZarinPal now answers 101 for the earlier verification.
        $this->queue('verify', $this->verifyOk(101, 424242));
        $this->getJson('/api/deposits/callback/zarinpal?Authority=AUTHTIMEOUT&Status=OK')->assertJsonPath('result', 'confirmed');

        $this->assertSame('424242', $deposit->refresh()->provider_reference);
        $this->assertSame(10_000, $wallet->refresh()->balance);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_creation_timeout_is_reported_as_unavailable_and_retry_reuses_the_deposit(): void
    {
        $this->queue('request', new ConnectionException('cURL error 28'));
        $wallet = $this->makeWallet();

        $this->initiate($wallet)->assertStatus(503)->assertJsonPath('error.code', 'provider_unavailable');
        $this->assertSame(1, Deposit::query()->count());

        $this->queue('request', $this->requestOk('AUTHAFTERRETRY'));
        $this->initiate($wallet)->assertCreated();

        $this->assertSame(1, Deposit::query()->count());
        $this->assertSame('AUTHAFTERRETRY', Deposit::query()->firstOrFail()->provider_authority);
    }

    public function test_invalid_creation_responses_never_produce_a_redirect(): void
    {
        $wallet = $this->makeWallet();

        foreach ([
            ['data' => ['code' => 100], 'errors' => []],
            ['data' => ['code' => 100, 'authority' => 'bad authority!'], 'errors' => []],
            ['data' => [], 'errors' => ['code' => -9, 'message' => 'Validation error']],
            ['nonsense' => 1],
        ] as $i => $body) {
            $this->queue('request', $body);
            $this->initiate($wallet, 10_000, "k{$i}")->assertStatus($i === 2 ? 502 : 503);
        }

        $this->assertSame(0, Deposit::query()->whereNotNull('provider_authority')->count());
        $this->assertSame(0, $wallet->refresh()->balance);
    }

    public function test_secrets_do_not_leak_into_logs_or_responses(): void
    {
        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'wallet.providers.zarinpal.merchant_id' => 'SUPER-SECRET-MERCHANT-ID',
        ]);
        $wallet = $this->makeWallet();
        $deposit = $this->makeDeposit($wallet, 10_000, authority: 'AUTHSECRET');
        $deposit->update(['provider' => 'zarinpal', 'provider_amount' => 10_000, 'provider_currency' => 'IRR']);

        $this->queue('request', new ConnectionException('timeout'));
        $this->queue('verify', new ConnectionException('timeout'));
        $responses = [
            $this->initiate($wallet, 10_000, 's1')->getContent(),
            $this->getJson('/api/deposits/callback/zarinpal?Authority=AUTHSECRET&Status=OK')->getContent(),
        ];

        $records = json_encode(array_map(
            fn ($r) => $r->message.json_encode($r->context),
            Log::getLogger()->getHandlers()[0]->getRecords(),
        ));
        foreach ([...$responses, $records] as $text) {
            $this->assertStringNotContainsString('SUPER-SECRET-MERCHANT-ID', $text);
        }
        $this->assertNotSame('[]', $records, 'expected the failures to be logged');
    }

    public function test_provider_is_disabled_without_a_merchant_id(): void
    {
        config(['wallet.providers.zarinpal.enabled' => false]);

        $this->initiate($this->makeWallet())->assertStatus(422)->assertJsonPath('error.code', 'provider_disabled');
    }
}

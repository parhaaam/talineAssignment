<?php

namespace App\Payment\Fake;

use App\Enums\VerificationOutcome;
use App\Exceptions\{InvalidAmount, PaymentProviderException};
use App\Payment\Contracts\PaymentProvider;
use App\Payment\Data\{PaymentCreation, PaymentRequest, ProviderAmount, ProviderReturn, VerificationResult, VerifyRequest};

/** Deterministic provider for tests and local demos; outcomes are scripted through static state. */
class FakePaymentProvider implements PaymentProvider
{
    /** @var list<VerificationResult> */
    public static array $verifyQueue = [];

    public static ?VerificationResult $defaultVerify = null;

    public static ?PaymentProviderException $createFailure = null;

    /** @var list<VerifyRequest> */
    public static array $verifyCalls = [];

    /** @var list<PaymentRequest> */
    public static array $createCalls = [];

    private static int $sequence = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public static function reset(): void
    {
        self::$verifyQueue = [];
        self::$defaultVerify = null;
        self::$createFailure = null;
        self::$verifyCalls = [];
        self::$createCalls = [];
        self::$sequence = 0;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function toProviderAmount(int $amount, string $currencyCode): ProviderAmount
    {
        // Mimics a gateway working in tomans so unit handling is exercised in tests.
        if ($amount % 10 !== 0) {
            throw new InvalidAmount('The amount must be a multiple of 10 rials for this provider.');
        }

        return new ProviderAmount(intdiv($amount, 10), 'IRT');
    }

    public function createPayment(PaymentRequest $request): PaymentCreation
    {
        self::$createCalls[] = $request;

        if (self::$createFailure !== null) {
            throw self::$createFailure;
        }

        $authority = 'FAKE'.str_pad((string) ++self::$sequence, 10, '0', STR_PAD_LEFT);

        return new PaymentCreation('fake', $authority, $this->getRedirectUrl($authority));
    }

    public function getRedirectUrl(string $authority): string
    {
        return 'https://fake-gateway.test/pay/'.$authority;
    }

    public function parseReturn(array $query): ProviderReturn
    {
        $authority = $query['Authority'] ?? null;

        return new ProviderReturn(
            'fake',
            is_string($authority) && preg_match('/^[A-Za-z0-9]{1,64}$/', $authority) ? $authority : null,
            ($query['Status'] ?? null) === 'OK',
        );
    }

    public function verifyPayment(VerifyRequest $request): VerificationResult
    {
        self::$verifyCalls[] = $request;

        return array_shift(self::$verifyQueue)
            ?? self::$defaultVerify
            ?? new VerificationResult(VerificationOutcome::Pending);
    }
}

<?php

namespace App\Payment\ZarinPal;

use App\Enums\VerificationOutcome;
use App\Exceptions\{CurrencyMismatch, InvalidAmount, PaymentProviderException};
use App\Payment\Contracts\PaymentProvider;
use App\Payment\Data\{PaymentCreation, PaymentRequest, ProviderAmount, ProviderReturn, VerificationResult, VerifyRequest};
use Illuminate\Http\Client\{ConnectionException, Response};
use Illuminate\Support\Facades\Http;

/**
 * ZarinPal API v4. Response codes follow https://www.zarinpal.com/docs/paymentGateway/errorList.
 * Error messages raised here never include the merchant id or raw responses.
 */
class ZarinPalProvider implements PaymentProvider
{
    private const PRODUCTION_API = 'https://payment.zarinpal.com/pg/v4/payment/';

    private const SANDBOX_API = 'https://sandbox.zarinpal.com/pg/v4/payment/';

    private const PRODUCTION_START_PAY = 'https://payment.zarinpal.com/pg/StartPay/';

    private const SANDBOX_START_PAY = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /** Verification answers that mean "this payment did not happen / cannot be verified". */
    private const NOT_VERIFIED_CODES = [-51, -54, -55];

    /** Configuration or request problems that retrying will not fix. */
    private const PERMANENT_CODES = [-9, -10, -11, -13, -14, -15, -16, -17, -18, -19, -50, -53];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string
    {
        return 'zarinpal';
    }

    public function toProviderAmount(int $amount, string $currencyCode): ProviderAmount
    {
        if ($currencyCode !== 'IRR') {
            throw new CurrencyMismatch('ZarinPal only supports IRR.');
        }

        // The API's currency defaults to IRT when omitted, so the unit is always stated explicitly.
        if (($this->config['currency'] ?? 'IRR') === 'IRT') {
            if ($amount % 10 !== 0) {
                throw new InvalidAmount('The amount must be a whole number of tomans (a multiple of 10 rials).');
            }

            return new ProviderAmount(intdiv($amount, 10), 'IRT');
        }

        return new ProviderAmount($amount, 'IRR');
    }

    public function createPayment(PaymentRequest $request): PaymentCreation
    {
        try {
            $response = $this->post('request.json', [
                'merchant_id' => $this->config['merchant_id'],
                'amount' => $request->amount->amount,
                'currency' => $request->amount->currency,
                'callback_url' => $request->callbackUrl,
                'description' => $request->description,
                'metadata' => ['order_id' => $request->reference],
            ]);
        } catch (ConnectionException) {
            // The request may have reached ZarinPal; the outcome is unknown.
            throw new PaymentProviderException('ZarinPal request timed out or was unreachable.', true);
        }

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $authority = $data['authority'] ?? null;

        if (($data['code'] ?? null) === 100 && is_string($authority) && preg_match('/^[A-Za-z0-9]{1,64}$/', $authority)) {
            return new PaymentCreation($this->name(), $authority, $this->getRedirectUrl($authority));
        }

        $code = $this->errorCode($body);
        if ($code !== null && in_array($code, self::PERMANENT_CODES, true)) {
            throw new PaymentProviderException("ZarinPal rejected the payment request (code {$code}).", false);
        }

        throw new PaymentProviderException('ZarinPal returned an unusable payment response.', true);
    }

    public function getRedirectUrl(string $authority): string
    {
        $base = ($this->config['sandbox'] ?? false) ? self::SANDBOX_START_PAY : self::PRODUCTION_START_PAY;

        return $base.$authority;
    }

    public function parseReturn(array $query): ProviderReturn
    {
        $authority = $query['Authority'] ?? null;

        return new ProviderReturn(
            $this->name(),
            is_string($authority) && preg_match('/^[A-Za-z0-9]{1,64}$/', $authority) ? $authority : null,
            ($query['Status'] ?? null) === 'OK',
        );
    }

    public function verifyPayment(VerifyRequest $request): VerificationResult
    {
        try {
            $response = $this->post('verify.json', [
                'merchant_id' => $this->config['merchant_id'],
                'amount' => $request->amount->amount,
                'authority' => $request->authority,
            ]);
        } catch (ConnectionException) {
            return new VerificationResult(VerificationOutcome::RetryableError);
        }

        if ($response->serverError()) {
            return new VerificationResult(VerificationOutcome::RetryableError, null, (string) $response->status());
        }

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $code = $data['code'] ?? null;

        if ($code === 100 || $code === 101) {
            $refId = $data['ref_id'] ?? null;
            if (! is_int($refId) && ! (is_string($refId) && $refId !== '')) {
                // A success code without a reference is malformed, not proof.
                return new VerificationResult(VerificationOutcome::Pending, null, (string) $code);
            }

            return new VerificationResult(
                $code === 100 ? VerificationOutcome::Verified : VerificationOutcome::AlreadyVerified,
                (string) $refId,
                (string) $code,
            );
        }

        $errorCode = $this->errorCode($body);
        if ($errorCode === null) {
            return new VerificationResult(VerificationOutcome::Pending);
        }

        $outcome = match (true) {
            in_array($errorCode, self::NOT_VERIFIED_CODES, true) => VerificationOutcome::NotVerified,
            in_array($errorCode, self::PERMANENT_CODES, true) => VerificationOutcome::PermanentError,
            $errorCode === -12 => VerificationOutcome::RetryableError,
            default => VerificationOutcome::Pending,
        };

        return new VerificationResult($outcome, null, (string) $errorCode);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $endpoint, array $payload): Response
    {
        $base = ($this->config['sandbox'] ?? false) ? self::SANDBOX_API : self::PRODUCTION_API;

        return Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3))
            ->timeout((int) ($this->config['timeout'] ?? 10))
            ->post($base.$endpoint, $payload);
    }

    private function errorCode(mixed $body): ?int
    {
        $errors = is_array($body) ? ($body['errors'] ?? null) : null;
        $code = is_array($errors) ? ($errors['code'] ?? null) : null;

        return is_int($code) ? $code : null;
    }
}

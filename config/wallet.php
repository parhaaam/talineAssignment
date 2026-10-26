<?php

use App\Payment\Fake\FakePaymentProvider;
use App\Payment\ZarinPal\ZarinPalProvider;

return [
    /*
    | Canonical unit: integer storage units of the currency (rials for IRR).
    | The largest amount a single operation may move.
    */
    'max_amount' => (int) env('WALLET_MAX_AMOUNT', 1_000_000_000_000),

    // Bounded retries when MySQL reports a deadlock inside a ledger transaction.
    'deadlock_retries' => (int) env('WALLET_DEADLOCK_RETRIES', 3),

    'history' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'zarinpal'),

    'providers' => [
        'zarinpal' => [
            'driver' => ZarinPalProvider::class,
            'enabled' => (bool) env('ZARINPAL_ENABLED', true) && env('ZARINPAL_MERCHANT_ID') !== null,
            'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
            'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
            // Unit sent to ZarinPal: IRR (default) or IRT. Conversion happens only inside the adapter.
            'currency' => env('ZARINPAL_CURRENCY', 'IRR'),
            'callback_url' => env('ZARINPAL_CALLBACK_URL', rtrim((string) env('APP_URL', 'http://localhost:8000'), '/').'/api/deposits/callback/zarinpal'),
            'timeout' => (int) env('ZARINPAL_TIMEOUT', 10),
            'connect_timeout' => (int) env('ZARINPAL_CONNECT_TIMEOUT', 3),
        ],

        'fake' => [
            'driver' => FakePaymentProvider::class,
            'enabled' => (bool) env('FAKE_PAYMENT_ENABLED', false),
            'callback_url' => env('FAKE_CALLBACK_URL', rtrim((string) env('APP_URL', 'http://localhost:8000'), '/').'/api/deposits/callback/fake'),
        ],
    ],
];

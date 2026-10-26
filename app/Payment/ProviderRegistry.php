<?php

namespace App\Payment;

use App\Exceptions\ProviderDisabled;
use App\Payment\Contracts\PaymentProvider;

/** Resolves providers from configuration only; clients can never select a class or URL. */
class ProviderRegistry
{
    public function resolve(string $name): PaymentProvider
    {
        $config = config('wallet.providers')[$name] ?? null;

        if ($config === null || ! ($config['enabled'] ?? false) || ! is_a($config['driver'] ?? '', PaymentProvider::class, true)) {
            throw new ProviderDisabled();
        }

        return new $config['driver']($config);
    }

    public function defaultName(): string
    {
        return (string) config('wallet.default_provider');
    }
}

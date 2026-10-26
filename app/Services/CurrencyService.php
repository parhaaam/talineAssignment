<?php

namespace App\Services;

use App\Exceptions\CurrencyInactive;
use App\Models\Currency;
use App\Repositories\{AccountRepository, CurrencyRepository};

class CurrencyService
{
    public function __construct(
        private readonly CurrencyRepository $currencies,
        private readonly AccountRepository $accounts,
    ) {
    }

    public function assertActive(Currency $currency): void
    {
        if (! $currency->is_active) {
            throw new CurrencyInactive();
        }
    }

    /** Registers a currency together with the payment-clearing account deposits need. */
    public function provision(string $code, string $name, ?string $symbol): Currency
    {
        $currency = $this->currencies->firstOrCreate($code, [
            'name' => $name,
            'symbol' => $symbol,
            'is_active' => true,
        ]);
        $this->accounts->firstOrCreateClearing($currency->id, $currency->code);

        return $currency;
    }
}

<?php

namespace App\Payment\Data;

/** The amount exactly as the provider expects it (its own unit), produced only at the provider boundary. */
final readonly class ProviderAmount
{
    public function __construct(public int $amount, public string $currency)
    {
    }
}

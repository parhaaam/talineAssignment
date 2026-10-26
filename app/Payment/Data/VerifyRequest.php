<?php

namespace App\Payment\Data;

final readonly class VerifyRequest
{
    public function __construct(public string $authority, public ProviderAmount $amount)
    {
    }
}

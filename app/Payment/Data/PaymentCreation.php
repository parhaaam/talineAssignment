<?php

namespace App\Payment\Data;

final readonly class PaymentCreation
{
    public function __construct(
        public string $provider,
        public string $authority,
        public string $redirectUrl,
    ) {
    }
}

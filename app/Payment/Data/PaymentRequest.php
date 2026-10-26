<?php

namespace App\Payment\Data;

final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public ProviderAmount $amount,
        public string $callbackUrl,
        public string $description,
    ) {
    }
}

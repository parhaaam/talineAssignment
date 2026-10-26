<?php

namespace App\Payment\Data;

/** Untrusted customer-return parameters, normalized. `reportedOk` is never proof of payment. */
final readonly class ProviderReturn
{
    public function __construct(
        public string $provider,
        public ?string $authority,
        public bool $reportedOk,
    ) {
    }
}

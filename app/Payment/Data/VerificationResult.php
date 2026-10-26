<?php

namespace App\Payment\Data;

use App\Enums\VerificationOutcome;

final readonly class VerificationResult
{
    public function __construct(
        public VerificationOutcome $outcome,
        public ?string $providerReference = null,
        public ?string $providerCode = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return in_array($this->outcome, [VerificationOutcome::Verified, VerificationOutcome::AlreadyVerified], true)
            && $this->providerReference !== null && $this->providerReference !== '';
    }
}

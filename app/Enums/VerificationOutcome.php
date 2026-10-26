<?php

namespace App\Enums;

enum VerificationOutcome: string
{
    case Verified = 'verified';
    case AlreadyVerified = 'already_verified';
    case NotVerified = 'not_verified';
    case Pending = 'pending';
    case RetryableError = 'retryable_error';
    case PermanentError = 'permanent_error';
}

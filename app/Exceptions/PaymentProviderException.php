<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised by provider adapters when a payment could not be created; never carries credentials. */
class PaymentProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}

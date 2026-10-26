<?php

namespace App\Exceptions;

class CurrencyMismatch extends DomainException
{
    public function __construct(string $message = 'The currencies do not match.')
    {
        parent::__construct($message, 'currency_mismatch', 422);
    }
}

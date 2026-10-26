<?php

namespace App\Exceptions;

class CurrencyInactive extends DomainException
{
    public function __construct(string $message = 'The currency is not active.')
    {
        parent::__construct($message, 'currency_inactive', 422);
    }
}

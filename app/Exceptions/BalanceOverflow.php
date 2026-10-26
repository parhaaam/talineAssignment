<?php

namespace App\Exceptions;

class BalanceOverflow extends DomainException
{
    public function __construct(string $message = 'The operation would overflow the wallet balance.')
    {
        parent::__construct($message, 'balance_overflow', 422);
    }
}

<?php

namespace App\Exceptions;

class DepositNotFound extends DomainException
{
    public function __construct(string $message = 'Deposit not found.')
    {
        parent::__construct($message, 'deposit_not_found', 404);
    }
}

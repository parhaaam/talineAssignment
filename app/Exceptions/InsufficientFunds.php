<?php

namespace App\Exceptions;

class InsufficientFunds extends DomainException
{
    public function __construct(string $message = 'Insufficient funds in the sender wallet.')
    {
        parent::__construct($message, 'insufficient_funds', 422);
    }
}

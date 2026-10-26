<?php

namespace App\Exceptions;

class InvalidAmount extends DomainException
{
    public function __construct(string $message = 'The amount is invalid.')
    {
        parent::__construct($message, 'invalid_amount', 422);
    }
}

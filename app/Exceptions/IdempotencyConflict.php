<?php

namespace App\Exceptions;

class IdempotencyConflict extends DomainException
{
    public function __construct(string $message = 'The idempotency key was already used with a different request.')
    {
        parent::__construct($message, 'idempotency_conflict', 409);
    }
}

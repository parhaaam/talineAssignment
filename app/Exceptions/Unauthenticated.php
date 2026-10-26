<?php

namespace App\Exceptions;

class Unauthenticated extends DomainException
{
    public function __construct(string $message = 'Missing or unknown user.')
    {
        parent::__construct($message, 'unauthenticated', 401);
    }
}

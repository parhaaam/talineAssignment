<?php

namespace App\Exceptions;

class Forbidden extends DomainException
{
    public function __construct(string $message = 'You do not have access to this resource.')
    {
        parent::__construct($message, 'forbidden', 403);
    }
}

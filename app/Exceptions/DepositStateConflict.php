<?php

namespace App\Exceptions;

class DepositStateConflict extends DomainException
{
    public function __construct(string $message = 'The deposit is in a state that does not allow this operation.')
    {
        parent::__construct($message, 'deposit_state_conflict', 409);
    }
}

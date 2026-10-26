<?php

namespace App\Exceptions;

class SelfTransfer extends DomainException
{
    public function __construct(string $message = 'Sender and recipient wallets must be different.')
    {
        parent::__construct($message, 'self_transfer', 422);
    }
}

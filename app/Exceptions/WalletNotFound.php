<?php

namespace App\Exceptions;

class WalletNotFound extends DomainException
{
    public function __construct(string $message = 'Wallet not found.')
    {
        parent::__construct($message, 'wallet_not_found', 404);
    }
}

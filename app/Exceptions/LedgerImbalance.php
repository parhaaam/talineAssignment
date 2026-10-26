<?php

namespace App\Exceptions;

class LedgerImbalance extends DomainException
{
    public function __construct(string $message = 'The ledger entries are not balanced.')
    {
        parent::__construct($message, 'ledger_imbalance', 500);
    }
}

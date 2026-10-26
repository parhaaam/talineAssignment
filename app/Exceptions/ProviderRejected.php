<?php

namespace App\Exceptions;

class ProviderRejected extends DomainException
{
    public function __construct(string $message = 'The payment provider rejected the request.')
    {
        parent::__construct($message, 'provider_rejected', 502);
    }
}

<?php

namespace App\Exceptions;

class ProviderDisabled extends DomainException
{
    public function __construct(string $message = 'The payment provider is not available.')
    {
        parent::__construct($message, 'provider_disabled', 422);
    }
}

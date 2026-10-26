<?php

namespace App\Exceptions;

class ProviderUnavailable extends DomainException
{
    public function __construct(string $message = 'The payment provider is temporarily unavailable. Please retry.')
    {
        parent::__construct($message, 'provider_unavailable', 503);
    }
}

<?php

namespace App\Support;

final class CanonicalHash
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

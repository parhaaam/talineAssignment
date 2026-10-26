<?php

namespace App\Enums;

/** Stored as a small integer; new types get new numbers, existing numbers never change. */
enum AccountType: int
{
    case Wallet = 1;
    case PaymentClearing = 2;

    public function label(): string
    {
        return match ($this) {
            self::Wallet => 'wallet',
            self::PaymentClearing => 'payment_clearing',
        };
    }
}

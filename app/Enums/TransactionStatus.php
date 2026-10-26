<?php

namespace App\Enums;

/** Stored as a small integer; new statuses get new numbers, existing numbers never change. */
enum TransactionStatus: int
{
    case Posted = 1;

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'posted',
        };
    }
}

<?php

namespace App\Support;

use App\Exceptions\{BalanceOverflow, InsufficientFunds, InvalidAmount};

/** Integer-only money helpers; amounts are in the currency's storage unit (rials for IRR). */
final class Money
{
    public static function assertValidOperationAmount(mixed $amount): int
    {
        if (! is_int($amount) || $amount <= 0) {
            throw new InvalidAmount('The amount must be a positive integer.');
        }

        if ($amount > (int) config('wallet.max_amount')) {
            throw new InvalidAmount('The amount exceeds the maximum allowed.');
        }

        return $amount;
    }

    public static function add(int $balance, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $balance) {
            throw new BalanceOverflow();
        }

        return $balance + $amount;
    }

    public static function subtract(int $balance, int $amount): int
    {
        if ($amount > $balance) {
            throw new InsufficientFunds();
        }

        return $balance - $amount;
    }
}

<?php

namespace App\Support;

use App\Exceptions\LedgerImbalance;

final class LedgerInvariant
{
    /**
     * @param  array<int, int>  $entryAmounts  signed amounts keyed by account id
     */
    public static function assertBalanced(array $entryAmounts): void
    {
        if (count($entryAmounts) < 2) {
            throw new LedgerImbalance('A transaction needs at least two entries.');
        }

        $sum = 0;
        foreach ($entryAmounts as $amount) {
            if ($amount === 0) {
                throw new LedgerImbalance('Entries must be non-zero.');
            }
            $sum += $amount;
        }

        if ($sum !== 0) {
            throw new LedgerImbalance();
        }
    }
}

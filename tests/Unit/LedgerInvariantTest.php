<?php

namespace Tests\Unit;

use App\Exceptions\LedgerImbalance;
use App\Support\LedgerInvariant;
use PHPUnit\Framework\TestCase;

class LedgerInvariantTest extends TestCase
{
    public function test_balanced_entries_pass(): void
    {
        LedgerInvariant::assertBalanced([1 => -100, 2 => 100]);
        LedgerInvariant::assertBalanced([1 => -100, 2 => 60, 3 => 40]);
        $this->addToAssertionCount(1);
    }

    public function test_unbalanced_single_or_zero_entries_are_rejected(): void
    {
        foreach ([[1 => -100, 2 => 99], [1 => 100], [], [1 => 0, 2 => 0], [1 => -5, 2 => 5, 3 => 0]] as $entries) {
            try {
                LedgerInvariant::assertBalanced($entries);
                $this->fail('expected an imbalance: '.json_encode($entries));
            } catch (LedgerImbalance) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

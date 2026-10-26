<?php

namespace Tests\Unit;

use App\Exceptions\{BalanceOverflow, InsufficientFunds, InvalidAmount};
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('invalid')]
    public function test_rejects_non_positive_or_non_integer_amounts(mixed $amount): void
    {
        $this->expectException(InvalidAmount::class);
        Money::assertValidOperationAmount($amount);
    }

    public static function invalid(): array
    {
        return [[0], [-1], [1.5], ['10'], [null], [true], [PHP_INT_MAX]];
    }

    public function test_accepts_amounts_up_to_the_configured_maximum(): void
    {
        config(['wallet.max_amount' => 100]);

        $this->assertSame(100, Money::assertValidOperationAmount(100));
        $this->expectException(InvalidAmount::class);
        Money::assertValidOperationAmount(101);
    }

    public function test_add_checks_for_overflow_before_adding(): void
    {
        $this->assertSame(PHP_INT_MAX, Money::add(PHP_INT_MAX - 1, 1));
        $this->expectException(BalanceOverflow::class);
        Money::add(PHP_INT_MAX, 1);
    }

    public function test_subtract_never_goes_negative(): void
    {
        $this->assertSame(0, Money::subtract(5, 5));
        $this->expectException(InsufficientFunds::class);
        Money::subtract(5, 6);
    }
}

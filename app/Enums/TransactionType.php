<?php

namespace App\Enums;

/** Stored as a small integer; new types get new numbers, existing numbers never change. */
enum TransactionType: int
{
    case Transfer = 1;
    case Deposit = 2;

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'transfer',
            self::Deposit => 'deposit',
        };
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (self $type) => $type->label(), self::cases());
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->label() === $label) {
                return $type;
            }
        }

        return null;
    }
}

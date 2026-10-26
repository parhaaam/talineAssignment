<?php

namespace App\Enums;

/** Stored as a small integer in lifecycle order; new statuses get new numbers, existing numbers never change. */
enum DepositStatus: int
{
    case Pending = 1;
    case VerificationUnknown = 2;
    case Verified = 3;
    case Confirmed = 4;
    case Failed = 5;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::VerificationUnknown => 'verification_unknown',
            self::Verified => 'verified',
            self::Confirmed => 'confirmed',
            self::Failed => 'failed',
        };
    }
}

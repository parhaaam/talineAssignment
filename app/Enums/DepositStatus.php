<?php

namespace App\Enums;

enum DepositStatus: string
{
    case Pending = 'pending';
    case VerificationUnknown = 'verification_unknown';
    case Verified = 'verified';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}

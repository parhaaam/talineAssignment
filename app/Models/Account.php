<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['wallet_id', 'currency_id', 'code', 'name', 'type'])]
class Account extends Model
{
    protected function casts(): array
    {
        return ['type' => AccountType::class];
    }
}

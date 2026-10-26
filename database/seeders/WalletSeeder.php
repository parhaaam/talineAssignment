<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    public function run(WalletService $wallets): void
    {
        // Wallets are provisioned here only; there is no wallet-creation endpoint.
        foreach (User::query()->get() as $user) {
            $wallets->provision($user->id, 'IRR');
        }
    }
}

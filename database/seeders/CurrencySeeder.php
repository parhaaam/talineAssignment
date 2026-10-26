<?php

namespace Database\Seeders;

use App\Services\CurrencyService;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(CurrencyService $currencies): void
    {
        // IRR has no minor unit: stored integers are whole rials.
        $currencies->provision('IRR', 'Iranian Rial', 'ریال');
    }
}

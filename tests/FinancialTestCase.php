<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinancialFixtures;

abstract class FinancialTestCase extends TestCase
{
    use FinancialFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencies();
    }
}

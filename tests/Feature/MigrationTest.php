<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{Artisan, Schema};
use Tests\TestCase;

/** Runs against the real testing database, without the per-test transaction wrapper. */
class MigrationTest extends TestCase
{
    public function test_migrations_apply_to_a_fresh_database_and_roll_back_cleanly(): void
    {
        try {
            Artisan::call('migrate:fresh');
            foreach (['currencies', 'wallets', 'accounts', 'transactions', 'entries', 'deposits', 'idempotency_records'] as $table) {
                $this->assertTrue(Schema::hasTable($table), "{$table} missing after migrate:fresh");
            }

            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 7]));
            foreach (['currencies', 'wallets', 'accounts', 'transactions', 'entries', 'deposits', 'idempotency_records'] as $table) {
                $this->assertFalse(Schema::hasTable($table), "{$table} survived rollback");
            }
            $this->assertTrue(Schema::hasTable('users'));
        } finally {
            Artisan::call('migrate:fresh');
        }
    }
}

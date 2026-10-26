<?php

namespace Tests\Concurrency;

use App\Enums\VerificationOutcome;
use App\Payment\Data\VerificationResult;
use App\Payment\Fake\FakePaymentProvider;
use App\UseCases\{CreateTransferUseCase, HandleDepositReturnUseCase, InitiateDepositUseCase};
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Tests\Support\FinancialFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Real parallelism via the Concurrency facade: each operation runs in its own PHP
 * process with its own MySQL connection, all released at a shared start time. Data is committed (no wrapping
 * transaction), so these tests exercise genuine row locks and unique indexes.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;
    use FinancialFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', config('database.default'), 'concurrency tests must run against MySQL');
        $this->seedCurrencies();
    }

    protected function tearDown(): void
    {
        // Leave the shared testing database clean for transaction-wrapped tests.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>> one decoded result per job, in order
     */
    protected function runInParallel(array $jobs): array
    {
        // Children boot at different speeds; they all spin until this instant so the
        // operations themselves start together.
        $startAt = microtime(true) + 2.0;

        return Concurrency::driver('process')->run(
            array_map(fn (array $job) => self::task($job, $startAt), $jobs),
            timeout: 60,
        );
    }

    private static function task(array $job, float $startAt): Closure
    {
        return static function () use ($job, $startAt) {
            while (microtime(true) < $startAt) {
                usleep(200);
            }

            try {
                $result = match ($job['action']) {
                    'transfer' => app(CreateTransferUseCase::class)
                        ->execute($job['user_id'], $job['key'], $job['from'], $job['to'], $job['amount']),
                    'deposit' => app(InitiateDepositUseCase::class)
                        ->execute($job['user_id'], $job['key'], $job['wallet_id'], $job['amount'], 'fake'),
                    'callback' => (function () use ($job) {
                        FakePaymentProvider::$defaultVerify = new VerificationResult(VerificationOutcome::Verified, $job['ref'], '100');

                        return app(HandleDepositReturnUseCase::class)->execute('fake', ['Authority' => $job['authority'], 'Status' => 'OK']);
                    })(),
                };

                return ['ok' => true, 'result' => $result];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            }
        };
    }
}

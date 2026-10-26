<?php

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FinancialFixtures;
use Tests\TestCase;

/**
 * Real parallelism: each operation runs in its own PHP process with its own MySQL
 * connection, released together by a barrier file. Data is committed (no wrapping
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
        $dir = sys_get_temp_dir().'/wallet-concurrency-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $barrier = "{$dir}/go";
        $processes = [];

        foreach ($jobs as $i => $job) {
            $process = proc_open(
                [PHP_BINARY, __DIR__.'/worker.php', $barrier, "{$dir}/ready-{$i}", json_encode($job)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                array_merge(getenv(), $_ENV, ['APP_ENV' => 'testing']),
            );
            $processes[$i] = [$process, $pipes];
        }

        $deadline = microtime(true) + 60;
        while (count(glob("{$dir}/ready-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        touch($barrier);

        $results = [];
        foreach ($processes as $i => [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($process);
            $decoded = json_decode($stdout, true);
            $this->assertIsArray($decoded, "worker {$i} produced no result. stdout: {$stdout} stderr: {$stderr}");
            $results[$i] = $decoded;
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        return $results;
    }
}

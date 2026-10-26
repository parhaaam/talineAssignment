<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Models\{Entry, Wallet};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\FinancialTestCase;

class WalletTransactionHistoryTest extends FinancialTestCase
{
    private function history(Wallet $wallet, string $query = '', ?Wallet $as = null)
    {
        return $this->getJson("/api/wallets/{$wallet->id}/transactions".($query === '' ? '' : "?{$query}"), $this->headersFor($as ?? $wallet, null));
    }

    private function sendTransfer(Wallet $from, Wallet $to, int $amount, string $key)
    {
        return $this->postJson('/api/transfers', ['from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => $amount], $this->headersFor($from, $key))->assertCreated();
    }

    public function test_only_the_owner_can_read_a_wallets_history(): void
    {
        $mine = $this->makeWallet(balance: 100);
        $other = $this->makeWallet(balance: 100);

        $this->history($mine)->assertOk();
        $this->history($other, as: $mine)->assertForbidden();
        $this->getJson("/api/wallets/{$mine->id}/transactions")->assertUnauthorized();
        $this->history(new Wallet(['id' => 999_999]), as: $mine)->assertNotFound();
    }

    public function test_wallet_id_manipulation_never_exposes_other_records(): void
    {
        $mine = $this->makeWallet(balance: 100);
        $victim = $this->makeWallet(balance: 5_000);

        foreach ([$victim->id, "{$victim->id}%20OR%201=1", '0', '-1'] as $id) {
            $response = $this->getJson("/api/wallets/{$id}/transactions", $this->headersFor($mine, null));
            $this->assertContains($response->status(), [403, 404]);
            $this->assertStringNotContainsString('5000', $response->getContent());
        }
    }

    public function test_direction_amount_and_currency_are_from_the_requested_wallets_perspective(): void
    {
        $a = $this->makeWallet(balance: 1_000);
        $b = $this->makeWallet();
        $this->sendTransfer($a, $b, 250, 't1');

        $fromA = $this->history($a)->assertOk()->json('data');
        $fromB = $this->history($b)->json('data');

        $this->assertSame('debit', $fromA[0]['direction']);
        $this->assertSame('credit', $fromB[0]['direction']);
        $this->assertSame(250, $fromA[0]['amount']);
        $this->assertSame(250, $fromB[0]['amount']);
        $this->assertSame('IRR', $fromA[0]['currency']);
        $this->assertSame($fromA[0]['reference'], $fromB[0]['reference']);
        $this->assertSame('posted', $fromA[0]['status']);
        $this->assertSame('deposit', $fromA[1]['type']);
        $this->assertNull($fromA[1]['counterparty_wallet_id']);
        $this->assertSame(2, Entry::query()->where('transaction_id', Entry::query()->max('transaction_id'))->count());
    }

    public function test_type_filter(): void
    {
        $a = $this->makeWallet(balance: 1_000);
        $b = $this->makeWallet();
        $this->sendTransfer($a, $b, 100, 't1');

        $this->assertSame(['transfer'], collect($this->history($a, 'type=transfer')->json('data'))->pluck('type')->all());
        $this->assertSame(['deposit'], collect($this->history($a, 'type=deposit')->json('data'))->pluck('type')->all());
        $this->history($a, 'type=withdrawal')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_date_range_is_inclusive_start_and_exclusive_end(): void
    {
        $a = $this->makeWallet();
        $b = $this->makeWallet();
        foreach (['2026-03-01 00:00:00', '2026-03-02 00:00:00', '2026-03-03 00:00:00'] as $i => $at) {
            CarbonImmutable::setTestNow($at);
            $this->fund($a, 100);
        }
        CarbonImmutable::setTestNow();

        $count = fn (string $q) => count($this->history($a, $q)->assertOk()->json('data'));

        $this->assertSame(3, $count(''));
        $this->assertSame(2, $count('from=2026-03-02T00:00:00Z'));               // start is inclusive
        $this->assertSame(2, $count('to=2026-03-03T00:00:00Z'));                 // end is exclusive
        $this->assertSame(1, $count('from=2026-03-02T00:00:00Z&to=2026-03-03T00:00:00Z'));
        $this->assertSame(1, $count('from=2026-03-02T03:30:00%2B03:30&to=2026-03-02T23:59:59Z')); // offsets normalize to UTC
        $this->assertSame(0, $count('from=2027-01-01'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $a = $this->makeWallet();

        foreach (['from=not-a-date', 'to=2026-13-45', 'from=2026-03-02&to=2026-03-01', 'to=2026-03-01&from=2026-03-01', 'per_page=0', 'per_page=101', 'per_page=abc', 'per_page=-3'] as $query) {
            $this->history($a, $query)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_cursor_pagination_is_deterministic_and_bounded_even_with_equal_timestamps(): void
    {
        $a = $this->makeWallet();
        CarbonImmutable::setTestNow('2026-05-05 10:00:00'); // every transaction shares one timestamp
        foreach (range(1, 7) as $_) {
            $this->fund($a, 10);
        }
        CarbonImmutable::setTestNow();

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $query = 'per_page=3'.($cursor ? '&cursor='.urlencode($cursor) : '');
            $page = $this->history($a, $query)->assertOk();
            $this->assertLessThanOrEqual(3, count($page->json('data')));
            array_push($seen, ...array_column($page->json('data'), 'reference'));
            $cursor = $page->json('next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen), 'no transaction repeats or is skipped across pages');
        $this->assertSame($seen, array_values(collect($seen)->sortDesc()->all()), 'newest first, stable ordering');
        $this->assertSame($seen, array_column($this->history($a, 'per_page=100')->json('data'), 'reference'));
    }

    public function test_default_page_size_applies(): void
    {
        $a = $this->makeWallet();
        foreach (range(1, 25) as $_) {
            $this->fund($a, 1);
        }

        $page = $this->history($a)->assertOk();
        $this->assertCount(20, $page->json('data'));
        $this->assertNotNull($page->json('next_cursor'));
    }

    public function test_pending_and_failed_deposits_are_not_listed_as_transactions(): void
    {
        $a = $this->makeWallet();
        $this->makeDeposit($a, 1_000, DepositStatus::Pending, 'AUTHP');
        $this->makeDeposit($a, 1_000, DepositStatus::Failed, 'AUTHF');

        $this->assertSame([], $this->history($a)->assertOk()->json('data'));
    }

    public function test_history_uses_a_constant_number_of_queries(): void
    {
        $a = $this->makeWallet();
        $b = $this->makeWallet(balance: 100_000);
        foreach (range(1, 15) as $i) {
            $this->sendTransfer($b, $a, 10, "t{$i}");
        }

        DB::enableQueryLog();
        $this->history($a, 'per_page=15')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries, "history issued {$queries} queries (N+1?)");
    }
}

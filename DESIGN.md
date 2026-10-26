# Design

Legend: **[Implemented]** exists and is tested; **[Not implemented]** is a recommendation or known gap.

## 1. Schema and relationships [Implemented]

```
users 1─* wallets *─1 currencies
wallets 1─1 accounts (wallet accounts)      accounts *─1 currencies   (system accounts: wallet_id NULL)
transactions *─1 currencies   transactions 1─* entries *─1 accounts
deposits *─1 wallets, currencies; deposits 0..1─1 transactions
idempotency_records *─1 users; → deposits / transactions
```

Migrations are in `database/migrations`. Primary keys are `bigint` auto-increment; all money columns are `BIGINT` (signed for `balance`/`entries.amount`).

- `wallets.balance` has a `CHECK (balance >= 0)`; `(user_id, currency_id)` is unique.
- `accounts.wallet_id` is unique (many NULLs allowed in MySQL for system accounts); `code` is unique;
- `transactions.reference` (ULID) is unique; `amount > 0` is checked. Only posted operations live here.
- `entries.amount <> 0` is checked; `(transaction_id, account_id)` is unique; `(account_id, transaction_id)` serves history.
- `deposits`: unique `reference`, unique `(provider, provider_authority)`, unique `(provider, provider_reference)`, unique `transaction_id`.
- `idempotency_records`: unique `(user_id, scope, idempotency_key)`.

**Deviation:** the brief asks for "constrained string" columns (`type`, `status`, `accounts.type`). None of them is a DB `ENUM` or `CHECK`, because adding a value must not require DDL; they are validated by PHP backed enums (`app/Enums`) on the write path.
- `accounts.type`, `transactions.type` and `transactions.status` are `TINYINT UNSIGNED` columns backed by int enums (`AccountType`: 1 = wallet, 2 = payment_clearing; `TransactionType`: 1 = transfer, 2 = deposit; `TransactionStatus`: 1 = posted). The models cast them to the enums, and the API still returns the readable string through each enum's `label()`. Existing numbers must never be reassigned.
- The remaining categorical columns (`deposits.status`) is a plain `varchar`.
- The database therefore does not stop a rogue writer from inserting an unknown value; all writes go through repositories.

## 2. Double-entry ledger [Implemented]

Every posted operation is one `transactions` row and ≥ 2 `entries` whose signed amounts sum to zero. Negative = debit, positive = credit.

| Operation | Entries |
|---|---|
| Transfer of N | sender wallet account −N, recipient wallet account +N |
| Deposit of N | payment-clearing account −N, wallet account +N |

A transfer transaction's `amount` is N, not the sum of absolute entries. Both wallets' histories point at the same transaction. `LedgerInvariant::assertBalanced` runs inside the posting transaction before any write; account currency is checked against the wallet/deposit currency.

**Immutability:** the Eloquent `Entry` and `Transaction` models throw on update and delete, so ordinary business code cannot modify or delete posted history; corrections must be compensating transactions. This is enforced in the application only: raw SQL, another client, or a query-builder update that bypasses model events can still change those rows. Database-level protection is a future item, see §17.

## 3. Balance projection [Implemented] and reconciliation [Not implemented]

`wallets.balance` is a projection of the wallet account's entries, updated in the same transaction that inserts them. Tests assert `balance == SUM(entries)` after every money test (`assertLedgerConsistent`). A production reconciliation command that checks this for all wallets and reports (never repairs) discrepancies is **not implemented** (dropped from scope by decision); see §16–17.

## 4. Deposit lifecycle [Implemented]

```
pending ──verify ok──▶ verified ──post──▶ confirmed
   │  ▲                     │ (post fails: stays verified, retried on next visit)
   │  └ verification_unknown ◀── timeout / unknown / permanent provider error
   └──authoritative rejection (e.g. ZarinPal −51/−54/−55)──▶ failed
```

- `confirmed` means the ledger transaction committed. `transaction_id` is set only then.
- `failed` is set only on an authoritative provider rejection, never on a timeout, elapsed time or a browser `Status=NOK`.
- `failed` is never flipped to `confirmed` (and vice versa): a "verified" report for a failed deposit raises `deposit_state_conflict` and is logged for investigation; nothing is changed.
- `verified`/`confirmed` are never downgraded.
- Pending/failed/unknown deposits never touch balances or the ledger.

## 5–6. Why the browser return is not proof, and why verification is mandatory

The callback is an unauthenticated GET whose query string anyone can forge or replay. `Status=OK` and `Authority` are therefore only used to *look up* a stored deposit by `(provider, authority)`; they never select a wallet or amount and can never create a deposit (unknown authority → 404). Money moves only after the provider confirms the payment server-to-server (ZarinPal `verify`), which is also what finalizes the payment on ZarinPal's side — an unverified payment may be reversed by the gateway. The amount sent to verify is the one stored at initiation (`provider_amount`), never a browser value.

## 7–8. Provider interface and the ZarinPal flow [Implemented]

`App\Payment\Contracts\PaymentProvider`: `toProviderAmount`, `createPayment`, `getRedirectUrl`, `parseReturn`, `verifyPayment`, with DTOs in `App\Payment\Data`. `ProviderRegistry` resolves adapters from `config/wallet.php` only (unknown or disabled → `provider_disabled`); clients can only pick a configured name. `PaymentGatewayService` is the single (non-repository) collaborator services/use cases use to reach adapters; adapters never touch the DB or ledger. `FakePaymentProvider` gives deterministic tests.

Flow:
1. `POST /api/deposits`: validate → commit `pending` deposit + idempotency record → call `request.json` (no transaction/lock open) → commit authority + stored response → return `redirect_url` (`…/pg/StartPay/{authority}`).
2. Customer pays, ZarinPal redirects to `…/api/deposits/callback/zarinpal?Authority=…&Status=OK|NOK`.
3. `NOK` → nothing is credited or verified; response `cancelled`, deposit unchanged.
4. `OK` → `verify.json` (merchant id, stored amount, authority) outside any transaction → `100` (verified) or `101` (already verified) **and** a non-empty `ref_id` → persist `verified` + `provider_reference` + `provider_verified_at` in its own commit → post ledger transaction in a second transaction → `confirmed`.

ZarinPal answer mapping (error list: zarinpal.com/docs/paymentGateway/errorList): `100`/`101` + `ref_id` → verified/already verified; `-51`, `-54`, `-55` → authoritative not-verified (deposit `failed`); `-50` (amount mismatch), `-53`, `-9…-11`, `-13…-19` → permanent error (deposit stays unresolved, logged, never failed); `-12` (too many attempts), HTTP 5xx, timeouts → retryable; success code without `ref_id`, unknown codes, malformed bodies → pending. HTTP 200 alone is never success. Documentation was checked via the official error list and SDK docs; I could not fetch the full API reference (JS-rendered) so request/response field names follow the v4 contract as documented in SDKs and are covered by mocked-HTTP tests, not by a live ZarinPal call.

## 9. Rial/toman handling [Implemented]

Canonical unit everywhere is the integer storage unit of the currency (rials for IRR). Conversion happens only in the adapter's `toProviderAmount`. ZarinPal's `currency` parameter defaults to IRT when omitted, so the adapter always sends it explicitly (`IRR` by default). With `ZARINPAL_CURRENCY=IRT` the adapter divides by 10 and rejects amounts not divisible by 10 *before* anything is stored. The provider amount and unit are persisted (`provider_amount`, `provider_currency`) and reused verbatim for verification.

## 10. Client idempotency vs payment-return deduplication [Implemented]

- **Client idempotency** (`Idempotency-Key` header, scoped `(user, scope, key)`): protects the *client request*. `request_hash` (SHA-256 of the canonical payload) detects a reused key with a different payload → 409. Transfers insert the idempotency row inside the posting transaction, so it commits or rolls back with the money; a concurrent duplicate blocks on the unique index until the winner commits, then receives the winner's stored response. Validation failures are not stored (the key can be reused); only committed outcomes are. Responses never contain provider secrets.
- **Payment-return deduplication** protects the *deposit*: the callback is not idempotency-keyed (browsers cannot send one). Correctness comes from state, not from remembering events: the deposit row is locked, a confirmed deposit is returned as is, `deposits.transaction_id` is unique, and `postDeposit` re-checks everything under the lock. Customer returns are not persisted as events (no audit table); only the web server and application logs show who hit the callback.

## 11–12. Verified but not posted; already-verified; timeouts [Implemented]

Verification evidence (`verified`, `provider_reference`, `provider_verified_at`) is committed *before* posting. If posting fails (crash, DB outage), the deposit stays `verified`, the wallet is untouched (the posting transaction rolled back), and the next visit to the callback posts it **without calling the provider again**. If even the evidence write failed, the next visit re-verifies; ZarinPal answers `101` for an already-verified payment, which is accepted because the lookup was by the stored authority and amount. Timeouts and unknown answers move a deposit to `verification_unknown`, never `failed`, and never credit. Tests cover each of these (`DepositReturnTest`, `ZarinPalDepositFlowTest`).

Payment *creation* has no provider-side idempotency: if the request times out, the same idempotency key resumes the same local deposit and calls `request.json` again. A lost-response first call may leave an orphan authority at ZarinPal; it can never be credited because no deposit knows it.

## 13. Constraints, locks, concurrency assumptions [Implemented, MySQL 8.4 / InnoDB, REPEATABLE READ]

- Transfers lock both wallet rows `FOR UPDATE` in ascending id order, re-read balances under the lock, then write entries/balances/idempotency result in one transaction. `postDeposit` locks the deposit row, then the wallet row; transfers never lock deposits, so no lock cycle exists.
- Transactions use `DB::transaction($cb, wallet.deadlock_retries)` (default 3); Laravel retries only deadlock/serialization errors. No network call happens inside a transaction or while locks are held.
- Unique indexes are the arbiter for duplicates (idempotency key, authority, provider reference, `transaction_id`).
- Verified by the Concurrency suite against MySQL 8.4 with separate processes. A mutation check (removing the wallet `FOR UPDATE`) made the overspend and opposite-direction tests fail with lost updates, so the tests do detect missing locks. Not verified: PostgreSQL, other isolation levels, multi-primary/replicated MySQL.

## 14. Reconciliation of stale deposits [Not implemented]

There is no scheduled/CLI reconciliation. A `pending`/`verification_unknown`/`verified` deposit is only advanced when its callback URL is hit again. Risk: a customer who paid and never returns leaves an unverified payment that the gateway may reverse. Recommended: a `deposits:recover` command (indexed on `(status, created_at)`), prioritized by age, re-verifying with bounded backoff and posting verified ones through the same `DepositService` path; plus `wallets:reconcile` checking the §3 and §11-of-brief invariants and reporting without repairing.

## 15. Indexing and history [Implemented]

History reads `entries` by `(account_id, transaction_id)` (backward index scan), joins `transactions` by primary key, and filters `type` / `created_at` on those rows. The `EXPLAIN` against 200k entries spread over 2,000 accounts showed `Backward index scan` on `entries_account_id_transaction_id_index`, no filesort, `eq_ref` join on `PRIMARY`; no secondary index on `transactions` was needed (a `(created_at, id)` index would not be used by this query). Pagination is cursor-based (`cursorPaginate`), page size ≤ 100, one extra batched query for counterparties (constant query count, tested).

**Deviation:** ordering is `transactions.id DESC` rather than `created_at DESC, id DESC`. Ids are monotonic with posting time, ties on timestamps are impossible, and it keeps the seek index-only. Date filter: `from` inclusive, `to` exclusive, ISO-8601 normalized to UTC. Only posted transactions are listed; pending/failed deposits are not.

## 16. Known limitations and deliberate trade-offs

- **Not implemented (dropped from scope by decision):** reconciliation command, stale-deposit recovery job/command, scheduler. These leave brief requirements §11, §12.3 and acceptance items 11/16 only partially met: recovery exists but is *pull-based* (callback revisit), not automatic.
- MySQL-only SQL (`CHECK`, generated column, `FOR UPDATE`).
- Ledger immutability is application-level only (model guards), so raw SQL can still alter or delete posted `entries` and `transactions`.
- Categorical columns are not DB-constrained: three are integer-backed enums, the rest plain strings (see §1).
- `X-User-Id` stands in for authentication; any caller who can set it is that user.
- The ZarinPal adapter was tested only against mocked responses; sandbox availability is unverified.
- Wallet provisioning is seeder-only; no API to create wallets or currencies. Deactivating a currency blocks new transfers/deposits but does not block posting an already-verified deposit (money already moved at the gateway).
- No provider webhooks and no payment-event audit table (removed by decision); customer returns leave no database trace beyond the deposit's state changes.
- Logging goes to Laravel's default channel; there is no separate audit sink.
- Deposit idempotency record is created before the provider call, so a failed creation can leave a `failed` deposit under that key; a new key is required.

## 17. With another week

1. `deposits:recover` + `wallets:reconcile` commands, scheduled with `withoutOverlapping`/`onOneServer`, with a backlog metric for unverified payments.
2. Transactional outbox for notifications/events (dispatch-after-commit alone can lose events on a crash between commit and publish).
3. Authenticated provider webhook endpoint with event-id dedup, and a `payment_events` audit table for returns and notifications.
4. PostgreSQL migrations and running the Concurrency suite on it; CI workflow.
5. Wallet/currency admin endpoints with audit trail; compensating-transaction tooling.
6. Verify the ZarinPal sandbox end to end and add contract tests recorded from it.
7. Enforce ledger immutability in the database as well (e.g. with database-level triggers or restricted privileges), so posted history cannot be rewritten outside the application.

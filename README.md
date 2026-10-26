# Wallet & Ledger API

Laravel 13 / PHP 8.4 / MySQL 8.4. Wallet-to-wallet transfers, deposits through a payment gateway (ZarinPal), and paginated transaction history, on a double-entry ledger. Design rationale, trade-offs and known gaps are in [DESIGN.md](DESIGN.md).

Amounts are **integers in the currency's storage unit — rials for IRR** . Tomans are never accepted or returned by the API.

## Setup

Prerequisites: Docker with Compose.

```sh
docker compose up -d --build
docker compose exec app composer install
cp .env.example .env            # if .env does not exist yet
docker compose exec app php artisan key:generate   # if APP_KEY is empty
docker compose exec app php artisan migrate --seed
```

- API: http://localhost:8000/api (nginx → php-fpm). MySQL is on host port 3307.
- Databases: `wallet` (app) and `wallet_testing` (tests), user `wallet` / `secret` (see `.env.example`, `phpunit.xml`).
- `php artisan migrate --seed` creates the IRR currency + its payment-clearing account, two demo users and one IRR wallet (plus ledger account) per user. **There is no wallet-creation endpoint**; wallets come from `WalletSeeder`.

## Tests

All tests need MySQL (they use `wallet_testing`), so run them in the `app` container:

```sh
docker compose exec app php artisan test                         # everything
docker compose exec app php artisan test --testsuite=Feature     # HTTP/feature tests (transaction-wrapped)
docker compose exec app php artisan test --testsuite=Concurrency # real parallelism
docker compose exec app php artisan test --testsuite=Unit
```

The **Concurrency** suite runs each operation in its own PHP process via Laravel's `Concurrency` facade (`process` driver), each with its own MySQL connection, released together at a shared start time. It commits real data, so it exercises genuine InnoDB row locks and unique indexes (duplicate idempotency keys, competing transfers, opposite-direction transfers, concurrent payment returns). It has only been run against MySQL 8.4; PostgreSQL has not been tested.

No test makes a live HTTP request: ZarinPal is mocked with `Http::fake()` and the `fake` provider.

## Authentication stand-in

Auth is out of scope. Every endpoint except the provider callback expects `X-User-Id: <user id>` (a missing/unknown id → 401).

## API

| Method & path | Purpose |
|---|---|
| `POST /api/transfers` | Wallet-to-wallet transfer (idempotent) |
| `POST /api/deposits` | Start a gateway deposit (idempotent); returns the redirect URL |
| `GET /api/deposits/callback/{provider}` | Customer return from the gateway; verifies server-to-server, then posts |
| `GET /api/wallets/{wallet}/transactions` | Cursor-paginated history |

Full request/response/error documentation is written as PHPDoc on each controller method (`app/Http/Controllers/Api`) and turned into an OpenAPI 3.1 spec by [Scramble](https://scramble.dedoc.co):

- Interactive docs: http://localhost:8000/docs/api
- Spec (JSON): http://localhost:8000/docs/api.json, also committed as [`api.json`](api.json)
- Both routes are available in every environment except `production` (gate `viewApiDocs`, defined in `ApiDocsServiceProvider`).
- After changing a controller docblock, a FormRequest or a route, regenerate the file with `docker compose exec app php artisan scramble:export`. `ApiDocumentationTest` fails when `api.json` is stale.
 Errors share one shape: `{"error": {"code": "...", "message": "..."}}` (validation errors add `fields`).

```sh
# Transfer (Idempotency-Key is required; retrying with the same key + body returns the original result)
curl -X POST localhost:8000/api/transfers \
  -H 'X-User-Id: 1' -H 'Idempotency-Key: 6f9b1c7e-1' -H 'Content-Type: application/json' \
  -d '{"from_wallet_id":1,"to_wallet_id":2,"amount":1000}'

# Start a deposit (the client then redirects the customer to redirect_url)
curl -X POST localhost:8000/api/deposits \
  -H 'X-User-Id: 1' -H 'Idempotency-Key: 6f9b1c7e-2' -H 'Content-Type: application/json' \
  -d '{"wallet_id":1,"amount":50000,"provider":"zarinpal"}'

# History: filters type=transfer|deposit, from (inclusive), to (exclusive), per_page (max 100), cursor
curl -H 'X-User-Id: 1' 'localhost:8000/api/wallets/1/transactions?type=transfer&from=2026-01-01&per_page=20'
```

History is ordered newest-first by transaction id (monotonic with posting time) and paginated with an opaque `next_cursor`. Dates are ISO-8601, normalized to UTC; a value without an offset is read as UTC.

## ZarinPal configuration

| Variable | Meaning |
|---|---|
| `ZARINPAL_MERCHANT_ID` | Merchant id. **Required**; ZarinPal is treated as disabled when empty. Never commit it. |
| `ZARINPAL_ENABLED` | `true` (default) / `false` |
| `ZARINPAL_SANDBOX` | `true` switches to `sandbox.zarinpal.com` hosts |
| `ZARINPAL_CURRENCY` | Unit sent to ZarinPal: `IRR` (default, no conversion) or `IRT` (divides rials by 10 at the adapter; amounts not divisible by 10 are rejected) |
| `ZARINPAL_CALLBACK_URL` | Public URL ZarinPal returns the customer to. Default `APP_URL/api/deposits/callback/zarinpal` |
| `PAYMENT_DEFAULT_PROVIDER` | Provider used when the request omits `provider` (default `zarinpal`) |
| `WALLET_MAX_AMOUNT` | Largest single operation (default `1000000000000`) |

The currency is always sent to ZarinPal explicitly because its API defaults to IRT when omitted.

Sandbox caveat: community SDKs report that ZarinPal's sandbox is intermittently unavailable, and I could not exercise it from this environment. Sandbox mode only changes the base URLs; its behaviour is unverified.

### Simulating flows

- **Initiation, return, verification success/failure, duplicate returns, timeouts, already-verified (101), local posting failure and recovery** are all simulated deterministically in the automated tests: `tests/Feature/ZarinPalDepositFlowTest.php` (real adapter + mocked HTTP) and `tests/Feature/DepositReturnTest.php` (scripted `FakePaymentProvider`).
- Manually, with a real merchant id (ideally a sandbox one): `POST /api/deposits` with `provider: zarinpal`, open `redirect_url` in a browser, pay or cancel, and ZarinPal sends the browser to the callback URL, which verifies and credits. A pending (`verification_unknown`) result is recovered by opening/refreshing the same callback URL again.
- The `fake` provider (`FAKE_PAYMENT_ENABLED=true`) exists for tests; its verification outcomes are scripted in-process, so it cannot drive a multi-request manual demo.

## Adding another provider

1. Create `app/Payment/<Name>/<Name>Provider.php` implementing `App\Payment\Contracts\PaymentProvider` (`toProviderAmount`, `createPayment`, `getRedirectUrl`, `parseReturn`, `verifyPayment`). Normalize every gateway answer into `VerificationResult`; never treat a timeout or unknown code as failure, and never touch the database or ledger.
2. Register it in `config/wallet.php` under `providers` with its `driver` class, `enabled` flag and settings (including `callback_url`).
3. Add tests with mocked HTTP. No wallet, ledger or use-case code changes.

## Limitations and database-specific assumptions

- MySQL 8.4 only: row locks (`SELECT ... FOR UPDATE`) and `CHECK` constraints are MySQL syntax. PostgreSQL would need adjusted migrations.
- Ledger/reconciliation commands, stale-deposit recovery jobs and scheduling are **not implemented**; see DESIGN.md §16–17. A deposit that stays unresolved is recovered by revisiting the callback URL.
- Pending/failed deposits are not listed in history (only posted transactions).

# Wallet & Ledger — Backend Challenge

Laravel 13 / PHP 8.4 / MySQL 8.4 API for a digital wallet (deposit via gateway callbacks, transfers, transaction history).

The business rules are **not** in this file yet; they will be provided separately. Do not invent domain rules.

## Domain context (from the brief)
- Real money: numbers must be trustworthy and every wallet's history reconstructable long after the fact.
- Requests may be retried/duplicated (flaky mobile networks, auto-retrying services) → idempotency matters.
- Multiple app servers; the same wallet can be hit concurrently → correctness under concurrency matters.
- Gateway callbacks (confirm / fail) are not guaranteed exactly-once.
- Currency is IRR now, but design for more currencies later.
- Auth is out of scope: authenticated user/wallet IDs are assumed given.
- Deliverables: code + migrations + tests, `DESIGN.md`, `README.md` (how to run app and tests).

## Environment (Docker)
Services: `app` (php-fpm), `nginx` (http://localhost:8000), `mysql` (host port 3307; DBs `wallet` and `wallet_testing`).

```sh
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate   # if APP_KEY empty
docker compose exec app php artisan migrate
docker compose exec app php artisan test
```
Run all PHP/artisan/composer commands inside the `app` container. Tests run against MySQL (`wallet_testing`), not SQLite, so locking/concurrency behaves like production.

## Architecture (strict layering)
```
routes/api.php -> Controller -> UseCase -> Service -> Repository -> Eloquent Model
```
- **Controller** (`app/Http/Controllers/Api`): injects exactly **one** use case, runs it inside the shared simple exception handler, returns the response. No logic.
- **UseCase** (`app/UseCases`): one class per operation. Injects **only Services**. Orchestrates them, including non-atomic multi-step flows (e.g. an external call between two short repository transactions).
- **Service** (`app/Services`): business logic. Injects **only Repositories**. Sole exception: `PaymentGatewayService` also injects the `ProviderRegistry` (payment adapters in `app/Payment`, which never touch the DB).
- **Repository** (`app/Repositories`): injects **one and only one** Eloquent model; one repository per model. The only layer allowed to touch Eloquent.
  - **Exception — transactional repository:** when one operation must write several models atomically, a repository may inject **multiple models** and open/commit the DB transaction itself (with bounded deadlock retry) inside the repository method. Name it after the aggregate (e.g. `LedgerRepository`). It still never injects another repository, and plain single-model repositories remain the default.
- **Models** (`app/Models`): Eloquent only; no business logic.

Never skip or reverse layers (e.g. a controller calling a service, a service using a model directly, a repository injecting another repository; multi-model injection is allowed only for transactional repositories).

### Exception handling
Controllers execute the use case through a simple shared handler that maps exceptions to proper JSON error responses (status code + clear message) and writes an audit log entry when the failure warrants it. Domain exceptions live in `app/Exceptions`.

### Documentation
Every endpoint is documented in a **plain PHPDoc docblock on the controller method**, which [Scramble](https://scramble.dedoc.co) (`dedoc/scramble`) turns into the OpenAPI spec. Do **not** use PHP attributes or `@OA\...` annotations for docs.
- First line = operation summary; the paragraphs after it = description (Markdown).
- `@response array{...}` = success body; `@status 201` = success status when it is not 200.
- `@throws SomeDomainException` for every error the endpoint can return (status and code come from the exception class).
- Describe request fields with a `/** ... */` comment above each rule in the FormRequest.
- The spec is served at `/docs/api` (UI) and `/docs/api.json` (non-production only) and committed as `api.json`; regenerate it with `php artisan scramble:export` after changing any docblock, request or route (a test fails when it is stale).

### Testing
Every endpoint has a **feature test** (`tests/Feature`) covering all edge cases: validation, success, not found, duplicates/idempotent retries, concurrency-sensitive paths, and each domain error. Money behaviours get extra scrutiny. Genuinely parallel tests (separate processes, committed data) live in `tests/Concurrency` (`php artisan test --testsuite=Concurrency`).

## Conventions
- Code follows **PSR-12**, and imports from the same namespace are **merged into one grouped `use`** (`use App\Enums\{AccountType, DepositStatus};`, not one `use` line each). Both are enforced by `pint.json` (`psr12` preset + `group_import`); do not hand-format around it.
- Lint is part of every change: run `docker compose exec app vendor/bin/pint` to fix, and `docker compose exec app vendor/bin/pint --test` must pass (no files reported) before finishing.
- Money as integer minor units (no floats); every money row carries a currency. API amounts are rials (IRR); unit conversion happens only inside a payment adapter.
- No DB ENUMs or categorical CHECKs: categorical columns are validated by PHP backed enums in `app/Enums`. `accounts.type`, `transactions.type`, `transactions.status` and `deposits.status` are stored as small integers (int-backed enums with a `label()` for API output; never renumber a case). New categorical columns follow the same pattern.
- Keep comments sparse; explain *why*, not *what*.

<?php

namespace App\Repositories;

use App\Enums\DepositStatus;
use App\Exceptions\{DepositNotFound, DepositStateConflict, IdempotencyKeyTaken};
use App\Models\{Deposit, IdempotencyRecord};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional repository: the deposit and its idempotency record always change together. */
class DepositInitiationRepository
{
    public function __construct(
        private readonly Deposit $deposits,
        private readonly IdempotencyRecord $records,
    ) {
    }

    /**
     * @param  array{user_id: int, scope: string, key: string, request_hash: string, wallet_id: int, currency_id: int, provider: string, amount: int, provider_amount: int, provider_currency: string}  $data
     *
     * @throws IdempotencyKeyTaken
     */
    public function createPending(array $data): Deposit
    {
        return DB::transaction(function () use ($data) {
            try {
                $record = $this->records->newQuery()->create([
                    'user_id' => $data['user_id'],
                    'scope' => $data['scope'],
                    'idempotency_key' => $data['key'],
                    'request_hash' => $data['request_hash'],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new IdempotencyKeyTaken();
            }

            $deposit = $this->deposits->newQuery()->create([
                'wallet_id' => $data['wallet_id'],
                'currency_id' => $data['currency_id'],
                'reference' => 'DEP-'.Str::ulid(),
                'provider' => $data['provider'],
                'amount' => $data['amount'],
                'provider_amount' => $data['provider_amount'],
                'provider_currency' => $data['provider_currency'],
                'status' => DepositStatus::Pending,
            ]);

            $record->update(['deposit_id' => $deposit->id]);

            return $deposit->load('currency');
        }, (int) config('wallet.deadlock_retries'));
    }

    /**
     * Records the provider authority and the client-visible outcome together.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function recordAuthority(int $depositId, string $authority, string $redirectUrl): array
    {
        return DB::transaction(function () use ($depositId, $authority, $redirectUrl) {
            $deposit = $this->deposits->newQuery()->with('currency')->lockForUpdate()->find($depositId)
                ?? throw new DepositNotFound();
            $record = $this->records->newQuery()->where('deposit_id', $depositId)->lockForUpdate()->first()
                ?? throw new DepositNotFound();

            if ($record->response_status !== null) {
                // A concurrent retry finished first; its outcome is the logical outcome.
                return ['status' => $record->response_status, 'body' => $record->response_body];
            }

            if ($deposit->status !== DepositStatus::Pending) {
                throw new DepositStateConflict();
            }

            $deposit->update(['provider_authority' => $authority]);

            $body = [
                'deposit_reference' => $deposit->reference,
                'status' => $deposit->status->label(),
                'provider' => $deposit->provider,
                'amount' => $deposit->amount,
                'currency' => $deposit->currency->code,
                'redirect_url' => $redirectUrl,
            ];
            $record->update(['response_status' => 201, 'response_body' => $body]);

            return ['status' => 201, 'body' => $body];
        }, (int) config('wallet.deadlock_retries'));
    }
}

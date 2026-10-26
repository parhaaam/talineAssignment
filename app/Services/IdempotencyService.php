<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflict;
use App\Repositories\IdempotencyRecordRepository;

class IdempotencyService
{
    public function __construct(private readonly IdempotencyRecordRepository $records)
    {
    }

    /**
     * Returns the stored record for a repeated key (null when the key is new). A key reused
     * with a different payload is a conflict; validation failures are never stored, so they
     * can be retried with the same key.
     *
     * @return array{response_status: ?int, response_body: ?array<string, mixed>, deposit_id: ?int}|null
     */
    public function lookup(int $userId, string $scope, string $key, string $requestHash): ?array
    {
        $record = $this->records->find($userId, $scope, $key);

        if ($record === null) {
            return null;
        }

        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new IdempotencyConflict();
        }

        return [
            'response_status' => $record->response_status,
            'response_body' => $record->response_body,
            'deposit_id' => $record->deposit_id,
        ];
    }

    /**
     * After losing the insert race the winner is already committed.
     *
     * @return array{response_status: ?int, response_body: ?array<string, mixed>, deposit_id: ?int}
     */
    public function lookupWinner(int $userId, string $scope, string $key, string $requestHash): array
    {
        return $this->lookup($userId, $scope, $key, $requestHash)
            ?? throw new IdempotencyConflict('The idempotency key is being processed. Please retry.');
    }
}

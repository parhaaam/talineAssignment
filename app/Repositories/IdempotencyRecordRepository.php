<?php

namespace App\Repositories;

use App\Models\IdempotencyRecord;

class IdempotencyRecordRepository
{
    public function __construct(private readonly IdempotencyRecord $model)
    {
    }

    public function find(int $userId, string $scope, string $key): ?IdempotencyRecord
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();
    }
}

<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function __construct(private readonly User $model)
    {
    }

    public function find(int $id): ?User
    {
        return $this->model->newQuery()->find($id);
    }
}

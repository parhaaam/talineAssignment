<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;

class UserService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function find(int $id): ?User
    {
        return $this->users->find($id);
    }
}

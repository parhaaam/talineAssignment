<?php

namespace App\Repositories;

use App\Models\Currency;

class CurrencyRepository
{
    public function __construct(private readonly Currency $model)
    {
    }

    public function findByCode(string $code): ?Currency
    {
        return $this->model->newQuery()->where('code', $code)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function firstOrCreate(string $code, array $attributes): Currency
    {
        return $this->model->newQuery()->firstOrCreate(['code' => $code], $attributes);
    }
}

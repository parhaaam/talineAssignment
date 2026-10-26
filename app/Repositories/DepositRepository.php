<?php

namespace App\Repositories;

use App\Enums\DepositStatus;
use App\Exceptions\{DepositNotFound, DepositStateConflict};
use App\Models\Deposit;
use Illuminate\Support\Facades\DB;

/** Deposit state transitions; every transition re-reads the row under a lock. */
class DepositRepository
{
    public function __construct(private readonly Deposit $model)
    {
    }

    public function findByAuthority(string $provider, string $authority): ?Deposit
    {
        return $this->model->newQuery()->with('currency')
            ->where('provider', $provider)->where('provider_authority', $authority)->first();
    }

    public function find(int $id): ?Deposit
    {
        return $this->model->newQuery()->with('currency')->find($id);
    }

    public function markVerified(int $id, string $providerReference): Deposit
    {
        return DB::transaction(function () use ($id, $providerReference) {
            $deposit = $this->lock($id);

            switch ($deposit->status) {
                case DepositStatus::Confirmed:
                    return $deposit;
                case DepositStatus::Failed:
                    // Contradictory authoritative outcomes are never resolved silently.
                    throw new DepositStateConflict('The deposit was marked failed but the provider reports it verified.');
                case DepositStatus::Verified:
                    if ($deposit->provider_reference !== $providerReference) {
                        throw new DepositStateConflict('The provider reference differs from the one already recorded.');
                    }

                    return $deposit;
            }

            $deposit->update([
                'status' => DepositStatus::Verified,
                'provider_reference' => $providerReference,
                'provider_verified_at' => now(),
            ]);

            return $deposit;
        });
    }

    public function markVerificationUnknown(int $id): Deposit
    {
        return DB::transaction(function () use ($id) {
            $deposit = $this->lock($id);

            if ($deposit->status === DepositStatus::Pending) {
                $deposit->update(['status' => DepositStatus::VerificationUnknown]);
            }

            return $deposit;
        });
    }

    /** Only a not-yet-verified deposit can fail; a verified or confirmed one is left untouched. */
    public function markFailed(int $id): Deposit
    {
        return DB::transaction(function () use ($id) {
            $deposit = $this->lock($id);

            if (in_array($deposit->status, [DepositStatus::Pending, DepositStatus::VerificationUnknown], true)) {
                $deposit->update(['status' => DepositStatus::Failed, 'failed_at' => now()]);
            }

            return $deposit;
        });
    }

    private function lock(int $id): Deposit
    {
        return $this->model->newQuery()->with('currency')->lockForUpdate()->find($id)
            ?? throw new DepositNotFound();
    }
}

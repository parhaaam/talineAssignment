<?php

namespace App\UseCases;

use App\Exceptions\IdempotencyKeyTaken;
use App\Services\{IdempotencyService, TransferService, WalletService};
use App\Support\CanonicalHash;

class CreateTransferUseCase
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransferService $transfers,
        private readonly IdempotencyService $idempotency,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function execute(int $userId, string $key, int $fromWalletId, int $toWalletId, mixed $amount): array
    {
        $hash = CanonicalHash::of(['from' => $fromWalletId, 'to' => $toWalletId, 'amount' => $amount]);

        if ($stored = $this->idempotency->lookup($userId, TransferService::SCOPE, $key, $hash)) {
            return $this->replay($stored);
        }

        $sender = $this->wallets->requireOwned($fromWalletId, $userId);
        $recipient = $this->wallets->find($toWalletId);
        $amount = $this->transfers->validate($sender, $recipient, $amount);

        try {
            $result = $this->transfers->post($userId, $key, $hash, $fromWalletId, $toWalletId, $amount);
        } catch (IdempotencyKeyTaken) {
            return $this->replay($this->idempotency->lookupWinner($userId, TransferService::SCOPE, $key, $hash));
        }

        return $result + ['replayed' => false];
    }

    /**
     * @param  array{response_status: ?int, response_body: ?array<string, mixed>, deposit_id: ?int}  $stored
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    private function replay(array $stored): array
    {
        return ['status' => $stored['response_status'], 'body' => $stored['response_body'], 'replayed' => true];
    }
}

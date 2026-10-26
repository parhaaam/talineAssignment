<?php

namespace App\Http\Support;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shared handler controllers run their use case through: maps exceptions to JSON errors. */
class ControllerExecutor
{
    public function run(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            $this->log($e);

            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        } catch (Throwable $e) {
            // Internal details (SQL, provider payloads, credentials) never reach the client.
            Log::error('Unhandled exception in API request', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->error('server_error', 'An unexpected error occurred.', 500);
        }
    }

    public function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function log(DomainException $e): void
    {
        if ($e->status >= 500) {
            Log::error('Domain failure', ['code' => $e->errorCode, 'message' => $e->getMessage()]);
        } elseif (in_array($e->errorCode, ['deposit_state_conflict', 'ledger_imbalance'], true)) {
            Log::warning('Financial state conflict', ['code' => $e->errorCode, 'message' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Http\Requests;

class CreateTransferRequest extends IdempotentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Wallet to debit; it must belong to the authenticated user. */
            'from_wallet_id' => ['required', 'integer:strict', 'min:1'],
            /** Wallet to credit; it must differ from the sender and use the same currency. */
            'to_wallet_id' => ['required', 'integer:strict', 'min:1'],
            /**
             * Amount in the currency's storage unit (rials for IRR), a positive integer up to the configured maximum.
             *
             * @example 250000
             */
            'amount' => ['required', 'integer:strict', 'min:1', 'max:'.config('wallet.max_amount')],
        ];
    }
}

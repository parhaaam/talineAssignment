<?php

namespace App\Http\Requests;

class CreateDepositRequest extends IdempotentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Wallet to credit once the payment is verified; it must belong to the authenticated user. */
            'wallet_id' => ['required', 'integer:strict', 'min:1'],
            /**
             * Amount in the currency's storage unit (rials for IRR), a positive integer up to the configured maximum.
             *
             * @example 500000
             */
            'amount' => ['required', 'integer:strict', 'min:1', 'max:'.config('wallet.max_amount')],
            /**
             * Name of an enabled payment provider. Defaults to the configured default provider.
             *
             * @example zarinpal
             */
            'provider' => ['nullable', 'string', 'max:32'],
        ];
    }
}

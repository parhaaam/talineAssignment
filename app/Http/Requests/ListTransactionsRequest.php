<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Only transactions of this type. */
            'type' => ['nullable', Rule::in(TransactionType::labels())],
            /** Start of the range, inclusive (ISO-8601; read as UTC when no offset is given). */
            'from' => ['nullable', 'date'],
            /** End of the range, exclusive; it must be after `from`. */
            'to' => ['nullable', 'date', 'after:from'],
            /** Page size (default 20). */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('wallet.history.max_per_page')],
            /** Opaque cursor from the previous page's `next_cursor`. */
            'cursor' => ['nullable', 'string', 'max:512'],
        ];
    }
}

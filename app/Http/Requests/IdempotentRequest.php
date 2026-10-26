<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class IdempotentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The key travels in the Idempotency-Key header, so it is validated here instead of in
     * rules(), which keeps it out of the documented request body.
     */
    protected function getValidatorInstance(): Validator
    {
        /** @var Validator $validator */
        $validator = parent::getValidatorInstance();

        $validator->setData($validator->getData() + ['idempotency_key' => $this->header('Idempotency-Key')]);
        $validator->addRules(['idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[\x21-\x7E]+$/']]);

        return $validator;
    }

    public function idempotencyKey(): string
    {
        return $this->validated('idempotency_key');
    }
}

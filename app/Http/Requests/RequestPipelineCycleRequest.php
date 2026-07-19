<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestPipelineCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'broker_account_id' => ['required', 'integer', 'exists:broker_accounts,id'],
            'trigger' => ['required', Rule::in(['manual', 'diagnostic'])],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}

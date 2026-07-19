<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartPaperSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'broker_account_id' => ['required', 'integer', 'exists:broker_accounts,id'],
            'funding_mode' => ['required', Rule::in(['virtual', 'mirror'])],
            'virtual_capital' => ['nullable', 'required_if:funding_mode,virtual', 'numeric', 'min:1', 'max:100000000'],
            'evidence_eligible' => ['sometimes', 'boolean'],
            'strategy_version_id' => ['nullable', 'integer', 'exists:strategy_versions,id'],
            'universe_version_id' => ['nullable', 'integer', 'exists:universe_versions,id'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}

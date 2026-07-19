<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RuntimeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'process_name' => ['required', 'string', Rule::in(array_keys((array) config('operations.workloads', [])))],
            'action' => ['required', Rule::in(['start', 'pause', 'resume', 'restart'])],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}

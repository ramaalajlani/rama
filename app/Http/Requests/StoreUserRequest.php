<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required','string','max:255'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()->mixedCase()],
            'role' => ['required','string','in:branch_reception,hq_supervisor,hq_auditor'],

            // فرع مطلوب فقط للاستقبال
            'branch_id' => [
                'nullable',
                'exists:branches,id',
                'required_if:role,branch_reception',
            ],

            'status' => ['nullable','in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email'  => strtolower(trim((string)$this->email)),
            'name'   => trim((string)$this->name),
            'status' => $this->input('status') ?: 'active',
        ]);
    }
}
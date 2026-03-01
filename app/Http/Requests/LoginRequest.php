<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],

            // ✅ Login: لا تفرض Password Policy تبع التسجيل
            'password' => ['required', 'string'],

            'remember' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $remember = filter_var(
            $this->input('remember'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        $this->merge([
            'email'    => strtolower(trim((string) $this->input('email'))),
            'remember' => $remember ?? false, // ✅ خليها false بدل null
        ]);
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'حقل البريد الإلكتروني مطلوب للمتابعة.',
            'email.email'       => 'يرجى إدخال بريد إلكتروني بصيغة صحيحة (example@mail.com).',
            'password.required' => 'كلمة المرور مطلوبة للدخول إلى النظام.',
            'remember.boolean'  => 'قيمة remember يجب أن تكون true أو false.',
        ];
    }
}
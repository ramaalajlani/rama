<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'national_id'  => ['required', 'string', 'min:7', 'max:20'],
            'risk_level'   => ['required', Rule::in(['CRITICAL', 'WATCHLIST', 'DANGER', 'BANNED'])],
            'instructions' => ['required', 'string', 'max:500'],

            'first_name'   => ['required', 'string', 'max:50'],
            'last_name'    => ['required', 'string', 'max:50'],
            'father_name'  => ['nullable', 'string', 'max:50'],
            'mother_name'  => ['nullable', 'string', 'max:100'],
            'reason'       => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'national_id' => trim((string)$this->input('national_id')),
            'first_name'  => trim((string)$this->input('first_name')),
            'last_name'   => trim((string)$this->input('last_name')),
            'father_name' => $this->filled('father_name') ? trim((string)$this->input('father_name')) : null,
            'mother_name' => $this->filled('mother_name') ? trim((string)$this->input('mother_name')) : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'رقم الهوية مطلب أساسي لإتمام عملية الحظر.',
            'risk_level.in'        => 'يرجى اختيار مستوى خطورة معتمد من النظام.',
            'first_name.required'  => 'الاسم الأول ضروري لتوليد البصمة الرقمية الأمنية.',
            'instructions.required'=> 'يجب تحديد الإجراء المطلوب عند رصد الهدف.',
        ];
    }
}
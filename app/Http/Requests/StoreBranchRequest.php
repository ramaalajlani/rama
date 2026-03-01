<?php
// app/Http/Requests/StoreBranchRequest.php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy بالـ Controller
    }

    public function rules(): array
    {
        $branchParam = $this->route('branch');

        $branchId = $branchParam instanceof Branch
            ? (int) $branchParam->id
            : (is_numeric($branchParam) ? (int) $branchParam : null);

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'name')->ignore($branchId),
            ],

            'city' => ['required', 'string', 'max:50'],

            'manager_name' => ['nullable', 'string', 'max:100'],

            'address' => ['nullable', 'string', 'max:500'],

            'phone' => ['nullable', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],

            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'         => trim((string) $this->input('name', '')),
            'city'         => trim((string) $this->input('city', '')),
            'manager_name' => $this->filled('manager_name') ? trim((string) $this->input('manager_name')) : null,
            'phone'        => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'status'       => $this->input('status') ?: 'active',
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'يجب إدخال اسم الفرع.',
            'name.unique'   => 'اسم هذا الفرع مسجل مسبقاً، يرجى استخدام اسم مختلف.',
            'city.required' => 'يرجى تحديد المدينة التي يتبع لها الفرع.',
            'phone.regex'   => 'صيغة رقم الهاتف غير صحيحة.',
            'status.in'     => 'حالة الفرع يجب أن تكون active أو inactive.',
        ];
    }
}
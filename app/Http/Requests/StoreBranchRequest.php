<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * الصلاحية مفعلة هنا لأننا نتحكم بها بدقة أكبر في الـ Controller عبر الـ Policies
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * قواعد التحقق لضمان هوية فريدة لكل فرع
     */
    public function rules(): array
    {
        // جلب معرف الفرع في حال كانت العملية "تحديث" لتجنب تعارض الـ unique مع نفسه
        $branchId = $this->route('branch') instanceof \App\Models\Branch 
                    ? $this->route('branch')->id 
                    : $this->route('branch');

        return [
            'name' => [
                'required', 
                'string', 
                'max:255',
                // التأكد من عدم تكرار الاسم إلا لنفس السجل عند التحديث
                Rule::unique('branches', 'name')->ignore($branchId)
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'status'  => ['nullable', Rule::in(['active', 'inactive'])],
            
            // إضافة حقول اختيارية قد تحتاجينها في التقارير المركزية
            'manager_name' => ['nullable', 'string', 'max:100'],
            'city'         => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * رسائل تنبيهية واضحة لمستخدمي الـ HQ
     */
    public function messages(): array
    {
        return [
            'name.required' => 'يجب إدخال اسم الفرع.',
            'name.unique'   => 'اسم هذا الفرع مسجل مسبقاً في النظام، يرجى اختيار اسم مميز.',
            'status.in'     => 'حالة الفرع يجب أن تكون إما نشط (active) أو غير نشط (inactive).',
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * قواعد التحقق لضمان هوية فريدة وبيانات دقيقة لكل فرع
     */
    public function rules(): array
    {
        // استخراج المعرف بذكاء يدعم Route Model Binding
        $branchId = $this->route('branch') instanceof \App\Models\Branch 
                    ? $this->route('branch')->id 
                    : $this->route('branch');

        return [
            'name' => [
                'required', 
                'string', 
                'max:255',
                Rule::unique('branches', 'name')->ignore($branchId)
            ],
            'address' => ['nullable', 'string', 'max:500'],
            // تحسين: إضافة regex لضمان أن رقم الهاتف يحتوي على أرقام ورموز اتصال دولية فقط
            'phone'   => ['nullable', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            
            // تحديد الحالة الافتراضية 'active' في حال لم يتم إرسالها
            'status'  => ['required', Rule::in(['active', 'inactive'])],
            
            'manager_name' => ['nullable', 'string', 'max:100'],
            'city'         => ['required', 'string', 'max:50'], // جعل المدينة مطلوبة لأغراض الفلترة الأمنية
        ];
    }

    /**
     * تهيئة البيانات قبل التحقق (Sanitization)
     */
    protected function prepareForValidation()
    {
        $this->merge([
            // تنظيف الاسم من المسافات الزائدة لضمان دقة قاعدة الـ unique
            'name' => trim($this->name),
            // تعيين حالة افتراضية إذا كان الحقل فارغاً
            'status' => $this->status ?? 'active',
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'يجب إدخال اسم الفرع.',
            'name.unique'   => 'اسم هذا الفرع مسجل مسبقاً، يرجى استخدام اسم مختلف.',
            'phone.regex'   => 'صيغة رقم الهاتف غير صحيحة.',
            'status.required' => 'يجب تحديد حالة الفرع.',
            'city.required'   => 'يرجى تحديد المدينة التي يتبع لها الفرع.',
        ];
    }
}
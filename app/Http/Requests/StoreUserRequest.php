<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * الصلاحية تدار عادة عبر الـ Policy في الـ Controller
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * قواعد التحقق: إضافة معايير أمنية لكلمة المرور وتدقيق الأدوار
     */
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            
            'email'     => [
                'required', 
                'email', 
                'max:255', 
                'unique:users,email'
            ],
            
            'password'  => [
                'required', 
                Password::min(8)
                    ->letters()   // يجب أن يحتوي على أحرف
                    ->numbers()   // يجب أن يحتوي على أرقام
                    ->mixedCase() // أحرف كبيرة وصغيرة
            ],
            
            // الفرع مطلوب لجميع الموظفين ما عدا إدارة الـ HQ العليا
            'branch_id' => [
                'nullable', 
                'exists:branches,id',
                'required_unless:role,hq_admin,hq_auditor' 
            ],
            
            // التأكد من أن الدور المختار موجود ضمن الأدوار المتاحة في النظام
            'role'      => [
                'required', 
                'string', 
                'in:hq_admin,hq_auditor,branch_manager,receptionist,security_officer'
            ],
        ];
    }

    /**
     * تهيئة البيانات قبل التحقق
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'email' => strtolower(trim($this->email)),
            'name'  => trim($this->name),
        ]);
    }

    /**
     * رسائل الخطأ بالعربية
     */
    public function messages(): array
    {
        return [
            'email.unique'      => 'هذا البريد الإلكتروني مسجل لموظف آخر بالفعل.',
            'password.min'       => 'كلمة المرور ضعيفة، يجب أن تتكون من 8 رموز على الأقل.',
            'role.in'           => 'الدور الوظيفي المختار غير معرف في صلاحيات النظام.',
            'branch_id.required_unless' => 'يجب ربط الموظف بفرع معين ما لم يكن من الإدارة المركزية.',
        ];
    }
}
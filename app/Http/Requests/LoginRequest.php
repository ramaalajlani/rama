<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class LoginRequest extends FormRequest
{
    /**
     * السماح لجميع الزوار بالوصول لمحاولة تسجيل الدخول
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * قواعد التحقق: أضفنا قيوداً لمنع الطلبات العشوائية الضخمة
     */
    public function rules(): array
    {
        return [
            // إضافة 'max' تمنع المهاجم من إرسال نصوص طويلة جداً لإجهاد السيرفر
            'email'    => ['required', 'email', 'string', 'max:255'],
            
            // Password::default() يضمن توافق كلمة المرور مع معايير الأمان التي حددتها في AppServiceProvider
            'password' => ['required', 'string', 'min:8'], 
            
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * رسائل الخطأ بالعربية لتجربة مستخدم أفضل
     */
    public function messages(): array
    {
        return [
            'email.required'    => 'حقل البريد الإلكتروني مطلوب للمتابعة.',
            'email.email'       => 'يرجى إدخال بريد إلكتروني بصيغة صحيحة (example@mail.com).',
            'password.required' => 'كلمة المرور مطلوبة للدخول إلى النظام.',
            'password.min'      => 'كلمة المرور يجب ألا تقل عن 8 رموز.',
        ];
    }

    /**
     * ميزة احترافية: تهيئة البيانات قبل التحقق
     * لضمان عدم فشل الدخول بسبب مسافة زائدة (Space) في الإيميل بالخطأ
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'email' => strtolower(trim($this->email)),
        ]);
    }
}
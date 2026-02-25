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
            // الهوية: يجب أن تكون فريدة في القائمة السوداء لمنع التكرار
            'national_id'  => [
                'required', 
                'string', 
                'min:7', 
                'max:20', 
                Rule::unique('security_blacklists', 'national_id')->ignore($this->route('blacklist'))
            ],
            
            // مستويات الخطورة: أضفنا التصنيفات التي استُخدمت في الموديلات السابقة لضمان التوافق
            'risk_level'   => ['required', Rule::in(['CRITICAL', 'WATCHLIST', 'DANGER', 'BANNED'])],
            
            'instructions' => ['required', 'string', 'max:500'],
            
            // جعلنا الأسماء مطلوبة هنا لأن الـ SecurityService يحتاجها لتوليد الهاش الثلاثي
            'first_name'   => ['required', 'string', 'max:50'],
            'last_name'    => ['required', 'string', 'max:50'],
            'father_name'  => ['nullable', 'string', 'max:50'], 
            'mother_name'  => ['nullable', 'string', 'max:100'],
            'reason'       => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * ميزة "تنظيف البيانات" (Sanitization):
     * نزيل المسافات ونوحد شكل الحروف قبل التحقق لضمان مطابقة الهاش لاحقاً
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'national_id' => trim($this->national_id),
            'first_name'  => trim($this->first_name),
            'last_name'   => trim($this->last_name),
        ]);
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'رقم الهوية مطلب أساسي لإتمام عملية الحظر.',
            'national_id.unique'   => 'هذا الشخص مدرج بالفعل في القائمة السوداء.',
            'risk_level.in'        => 'يرجى اختيار مستوى خطورة معتمد من النظام.',
            'first_name.required'  => 'الاسم الأول ضروري لتوليد البصمة الرقمية الأمنية.',
            'instructions.required'=> 'يجب تحديد الإجراء المطلوب من الموظف عند رصد الهدف.',
        ];
    }
}
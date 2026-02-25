<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlacklistRequest extends FormRequest
{
    /**
     * التحقق من الصلاحية يتم عبر الـ Policy في الـ Controller، 
     * لكننا نتركه true هنا لعدم تكرار المنطق.
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * قواعد التحقق الصارمة لضمان دقة "الرصد الأمني"
     */
    public function rules(): array
    {
        return [
            // الهوية يجب أن تكون بتنسيق نصي سليم وبطول منطقي
            'national_id'  => ['required', 'string', 'min:7', 'max:20'],
            
            // مستويات الخطورة المعتمدة في نظامنا
            'risk_level'   => ['required', Rule::in(['CRITICAL', 'WATCHLIST'])],
            
            // التعليمات التي تظهر لموظف الاستقبال (يجب أن تكون واضحة)
            'instructions' => ['required', 'string', 'max:500'],
            
            // إضافات اختيارية لتعزيز قوة الهاش (تحدثنا عنها في الـ Service)
            'first_name'   => ['nullable', 'string', 'max:50'],
            'last_name'    => ['nullable', 'string', 'max:50'],
            'mother_name'  => ['nullable', 'string', 'max:100'],
            'reason'       => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * رسائل الخطأ المخصصة باللغة العربية لسهولة التعامل مع الـ HQ
     */
    public function messages(): array
    {
        return [
            'national_id.required'  => 'رقم الهوية مطلب أساسي لإتمام عملية الحظر.',
            'risk_level.in'         => 'يجب اختيار مستوى خطورة صالح (CRITICAL أو WATCHLIST).',
            'instructions.required' => 'يجب وضع تعليمات واضحة للموظفين عند ظهور هذا التنبيه.',
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // إذا عندك Policy وبتعمل authorize بالكنترولر، خليه true
        // والكنترولر هو اللي يقرر.
        return true;
    }

    public function rules(): array
    {
        return [
            // الهوية (مطلوبة دائماً)
            'national_id' => ['required', 'string', 'min:3', 'max:64'],

            // الأسماء (مطلوبة عند الإنشاء - لكن أنت ممكن تمررها دائماً)
            'first_name'  => ['required', 'string', 'min:2', 'max:100'],
            'father_name' => ['nullable', 'string', 'max:100'],
            'last_name'   => ['required', 'string', 'min:2', 'max:100'],
            'mother_name' => ['nullable', 'string', 'max:100'],

            // معلومات أساسية
            'id_type'     => ['required', 'string', 'max:50'],       // مثال: بطاقة شخصية / جواز
            'nationality' => ['required', 'string', 'max:80'],
            'phone'       => ['required', 'string', 'max:30'],

            // اختياري
            'email'       => ['nullable', 'email', 'max:150'],
            'address'     => ['nullable', 'string', 'max:255'],
            'car_plate'   => ['nullable', 'string', 'max:30'],

            // هذه الحقول إذا بدك تسمح فيها فقط من HQ خليهـا ممنوعة هنا
            // أو خليها nullable بس انتبه للسيكيوريتي
            'status'      => ['nullable', Rule::in(['active', 'blacklisted'])],
            'is_flagged'  => ['nullable', 'boolean'],
            'audit_status'=> ['nullable', Rule::in(['new', 'audited'])],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'رقم الهوية مطلوب.',
            'first_name.required'  => 'الاسم الأول مطلوب.',
            'last_name.required'   => 'الكنية مطلوبة.',
            'id_type.required'     => 'نوع الهوية مطلوب.',
            'nationality.required' => 'الجنسية مطلوبة.',
            'phone.required'       => 'رقم الهاتف مطلوب.',
            'email.email'          => 'صيغة البريد الإلكتروني غير صحيحة.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // تنظيف بسيط قبل التحقق (مثل اللي تعملو بالسيرفس)
        $nationalId = (string)($this->national_id ?? '');
        $nationalId = preg_replace('/\s+/', '', trim($nationalId)) ?? '';

        $this->merge([
            'national_id' => $nationalId,
            'first_name'  => isset($this->first_name) ? trim((string)$this->first_name) : null,
            'father_name' => isset($this->father_name) ? trim((string)$this->father_name) : null,
            'last_name'   => isset($this->last_name) ? trim((string)$this->last_name) : null,
            'mother_name' => isset($this->mother_name) ? trim((string)$this->mother_name) : null,
            'phone'       => isset($this->phone) ? trim((string)$this->phone) : null,
            'email'       => isset($this->email) && $this->email !== null ? mb_strtolower(trim((string)$this->email), 'UTF-8') : null,
            'address'     => isset($this->address) ? trim((string)$this->address) : null,
            'car_plate'   => isset($this->car_plate) ? trim((string)$this->car_plate) : null,
        ]);
    }

    /**
     * OPTIONAL: لو بدك تمنع تمرير status/is_flagged/audit_status من الفرع نهائياً
     * (أنصح فيه أمنياً)
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        // 🔒 إذا بدك تقفل هالحقول من الـ API العادي:
        unset($data['status'], $data['is_flagged'], $data['audit_status']);

        return $data;
    }
}
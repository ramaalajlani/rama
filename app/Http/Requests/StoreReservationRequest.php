<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check(); 
    }

    public function rules(): array
    {
        return [
            // --- بيانات الحجز الأساسية ---
            'branch_id'        => ['required', 'exists:branches,id'],
            'room_id'          => ['required', 'exists:rooms,id'],
            'check_in'         => ['required', 'date', 'after_or_equal:today'], 
            'check_out'        => ['required', 'date', 'after:check_in'],
            'status'           => ['nullable', 'string', 'in:confirmed,pending,checked_in,checked_out'],
            'security_notes'   => ['nullable', 'string', 'max:1000'],
            'vehicle_plate'    => ['nullable', 'string', 'max:20'], 

            // --- مصفوفة النزلاء (Occupants) ---
            'occupants'                   => ['required', 'array', 'min:1'], 
            'occupants.*.first_name'      => ['required', 'string', 'max:50'],
            'occupants.*.father_name'     => ['required', 'string', 'max:50'], 
            'occupants.*.mother_name'     => ['required', 'string', 'max:50'], 
            'occupants.*.last_name'       => ['required', 'string', 'max:50'],
            'occupants.*.national_id'     => ['required', 'string', 'max:50'], 
            'occupants.*.id_type'         => ['required', 'string', 'in:national_id,passport,residency'],
            'occupants.*.nationality'     => ['required', 'string', 'max:100'],
            'occupants.*.phone'           => ['nullable', 'string', 'max:20'],
            'occupants.*.is_primary'      => ['required'], 

            // الوثائق: البند 2 (صور فقط)
            'occupants.*.id_image'        => [
                'nullable',
                'image', 
                'mimes:jpg,jpeg,png,webp', 
                'max:4096' // 4MB لتجنب إجهاد السيرفر عند الرفع
            ],
        ];
    }

    protected function prepareForValidation()
    {
        $occupants = $this->input('occupants', []);
        
        if (is_array($occupants)) {
            foreach ($occupants as $key => $occupant) {
                // 1. تنظيف أرقام الهوية وتوحيدها (Data Normalization)
                if (isset($occupant['national_id'])) {
                    $occupants[$key]['national_id'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $occupant['national_id']));
                }

                // 2. معالجة قيم Boolean القادمة من FormData
                if (isset($occupant['is_primary'])) {
                    $val = $occupant['is_primary'];
                    $occupants[$key]['is_primary'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        $this->merge([
            'occupants'     => $occupants,
            'branch_id'     => $this->branch_id ?? auth()->user()->branch_id,
            // توحيد مسمى لوحة السيارة
            'vehicle_plate' => trim($this->vehicle_plate ?? $this->car_plate_number ?? ''),
        ]);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $occupants = $this->input('occupants', []);
            
            // 1. فحص النزيل الرئيسي
            $primaryCount = collect($occupants)->where('is_primary', true)->count();
            if ($primaryCount !== 1) {
                $validator->errors()->add('occupants', 'يجب تحديد نزيل رئيسي واحد فقط (Primary Guest).');
            }

            // 2. فحص تكرار الهوية داخل نفس الطلب (منع الخطأ البشري)
            $ids = collect($occupants)->pluck('national_id')->toArray();
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('occupants', 'لا يمكن تكرار رقم الهوية لأكثر من نزيل في نفس الحجز.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'occupants.*.first_name.required'  => 'الاسم الأول مطلوب لكل فرد.',
            'occupants.*.national_id.required' => 'رقم الهوية إلزامي للتدقيق الأمني.',
            'occupants.*.mother_name.required' => 'اسم الأم مطلوب (لأغراض المطابقة الثلاثية في القائمة السوداء).',
            'occupants.*.id_image.max'         => 'حجم صورة الهوية كبير جداً، الحد الأقصى 4 ميجابايت.',
            'check_out.after'                  => 'تاريخ المغادرة يجب أن يكون بعد تاريخ الدخول.',
        ];
    }
}
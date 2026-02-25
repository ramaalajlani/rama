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
            // --- بيانات الحجز ---
            'branch_id'        => ['required', 'exists:branches,id'],
            'room_id'          => ['required', 'exists:rooms,id'],
            'check_in'         => ['required', 'date'], 
            'check_out'        => ['required', 'date', 'after:check_in'],
            'status'           => ['nullable', 'string', 'in:confirmed,pending,checked_in'],
            'security_notes'   => ['nullable', 'string'],
            
            // تم توحيد المسمى ليطابق الموديل وقاعدة البيانات لضمان الحفظ التلقائي
            'vehicle_plate'    => ['nullable', 'string', 'max:20'], 
            'car_model'        => ['nullable', 'string', 'max:50'],

            // --- مصفوفة النزلاء ---
            'occupants'                   => ['required', 'array', 'min:1'], 
            'occupants.*.first_name'      => ['required', 'string', 'max:50'],
            'occupants.*.father_name'     => ['required', 'string', 'max:50'], 
            'occupants.*.mother_name'     => ['required', 'string', 'max:50'], 
            'occupants.*.last_name'       => ['required', 'string', 'max:50'],
            'occupants.*.national_id'     => ['required', 'string', 'max:50'], 
            'occupants.*.id_type'         => ['required', 'string', 'in:national_id,passport,residency'],
            'occupants.*.nationality'     => ['required', 'string', 'max:100'],
            'occupants.*.phone'           => ['nullable', 'string', 'max:20'],
            'occupants.*.is_primary'      => ['required'], // سنعالجها كـ Boolean في prepareForValidation

            // تصحيح شرط الصورة: إجبارية فقط للنزيل الرئيسي
            'occupants.*.id_image'        => [
                'nullable',
                'image', 
                'mimes:jpg,jpeg,png', 
                'max:4096' 
            ],
        ];
    }

    protected function prepareForValidation()
    {
        $occupants = $this->input('occupants', []);
        
        if (is_array($occupants)) {
            foreach ($occupants as $key => $occupant) {
                if (isset($occupant['national_id'])) {
                    $occupants[$key]['national_id'] = str_replace([' ', '-', '_'], '', $occupant['national_id']);
                }

                // تحويل القيم النصية القادمة من FormData إلى Boolean حقيقي
                if (isset($occupant['is_primary'])) {
                    $val = $occupant['is_primary'];
                    $occupants[$key]['is_primary'] = ($val === 'true' || $val === '1' || $val === 1 || $val === true);
                }
            }
        }

        // توحيد مسمى رقم السيارة القادم من الواجهة car_plate_number إلى vehicle_plate
        $this->merge([
            'occupants'     => $occupants,
            'vehicle_plate' => $this->vehicle_plate ?? $this->car_plate_number,
            'branch_id'     => $this->branch_id ?? (auth()->check() ? auth()->user()->branch_id : null),
        ]);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $occupants = $this->input('occupants', []);
            $primaryCount = collect($occupants)->filter(function($guest) {
                return ($guest['is_primary'] === true || $guest['is_primary'] === 1 || $guest['is_primary'] === 'true');
            })->count();

            if ($primaryCount !== 1) {
                $validator->errors()->add('occupants', 'يجب تحديد نزيل رئيسي واحد فقط لهذه العملية.');
            }
        });
    }
}
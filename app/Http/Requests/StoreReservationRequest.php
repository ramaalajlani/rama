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
            'branch_id'      => ['sometimes', 'integer', 'exists:branches,id'],
            'room_id'        => ['required', 'integer', 'exists:rooms,id'],
            'check_in'       => ['required', 'date'],
            'check_out'      => ['nullable', 'date', 'after:check_in'],

            'status'         => ['nullable', 'string', Rule::in(['pending','confirmed','checked_out','cancelled'])],
            'security_notes' => ['nullable', 'string', 'max:1000'],

            'vehicle_plate'  => ['nullable', 'string', 'max:20'],

            'occupants'                   => ['required', 'array', 'min:1'],
            'occupants.*.first_name'      => ['required', 'string', 'max:50'],
            'occupants.*.father_name'     => ['required', 'string', 'max:50'],
            'occupants.*.mother_name'     => ['required', 'string', 'max:50'],
            'occupants.*.last_name'       => ['required', 'string', 'max:50'],
            'occupants.*.national_id'     => ['required', 'string', 'max:50'],
            'occupants.*.id_type'         => ['required', 'string', Rule::in(['national_id','passport','residency'])],
            'occupants.*.nationality'     => ['required', 'string', 'max:100'],
            'occupants.*.phone'           => ['nullable', 'string', 'max:20'],

            'occupants.*.car_plate'       => ['nullable', 'string', 'max:20'],

            'occupants.*.is_primary'      => ['required', 'boolean'],
            'occupants.*.relationship'    => ['nullable', 'string', 'max:30'],
            'occupants.*.id_image'        => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $occupants = $this->input('occupants', []);

        if (is_array($occupants)) {
            foreach ($occupants as $i => $occ) {
                if (!is_array($occ)) continue;

                if (isset($occ['national_id'])) {
                    $nid = (string) $occ['national_id'];
                    $nid = preg_replace('/\s+/u', '', $nid);
                    $nid = preg_replace('/[^A-Za-z0-9]/u', '', $nid);
                    $occupants[$i]['national_id'] = $nid ?? '';
                }

                if (array_key_exists('is_primary', $occ)) {
                    $occupants[$i]['is_primary'] = filter_var(
                        $occ['is_primary'],
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    );
                    if ($occupants[$i]['is_primary'] === null) {
                        $occupants[$i]['is_primary'] = false;
                    }
                }

                foreach (['first_name','father_name','mother_name','last_name','nationality','phone','relationship','car_plate'] as $k) {
                    if (isset($occupants[$i][$k])) {
                        $occupants[$i][$k] = trim((string)$occupants[$i][$k]);
                    }
                }
            }
        }

        $vehiclePlate = $this->input('vehicle_plate')
            ?? $this->input('car_plate')
            ?? $this->input('car_plate_number')
            ?? '';

        $this->merge([
            'occupants'     => $occupants,
            'branch_id'     => $this->input('branch_id') ?? (auth()->user()->branch_id ?? null),
            'status'        => $this->input('status') ?? 'confirmed',
            'vehicle_plate' => trim((string)$vehiclePlate),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $occupants = $this->input('occupants', []);

            $primaryCount = collect($occupants)->where('is_primary', true)->count();
            if ($primaryCount !== 1) {
                $validator->errors()->add('occupants', 'يجب تحديد نزيل رئيسي واحد فقط.');
            }

            $ids = collect($occupants)->pluck('national_id')->filter()->toArray();
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('occupants', 'لا يمكن تكرار رقم الهوية لأكثر من نزيل داخل نفس الإقامة.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'room_id.required'                 => 'الغرفة مطلوبة.',
            'check_out.after'                  => 'تاريخ المغادرة يجب أن يكون بعد تاريخ الدخول.',
            'occupants.required'               => 'يجب إدخال نزيل واحد على الأقل.',
            'occupants.*.mother_name.required' => 'اسم الأم مطلوب للمطابقة الأمنية.',
            'occupants.*.national_id.required' => 'رقم الهوية إلزامي للتدقيق الأمني.',
            'occupants.*.is_primary.boolean'   => 'حقل النزيل الرئيسي يجب أن يكون true/false.',
            'occupants.*.id_image.max'         => 'حجم صورة الهوية كبير جداً (الحد الأقصى 4 ميجابايت).',
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        $roomId = $this->route('room') instanceof \App\Models\Room 
            ? $this->route('room')->id 
            : $this->route('room');

        return [
            // الفرع: نتحقق من وجوده وأنه يخص صلاحيات المستخدم إذا لم يكن HQ
            'branch_id' => ['required', 'exists:branches,id'],
            
            // رقم الغرفة: فحص فريد مركب (رقم الغرفة + الطابق + الفرع)
            'room_number' => [
                'required', 
                'string', 
                'max:20',
                Rule::unique('rooms')->where(function ($query) {
                    return $query->where('branch_id', $this->branch_id)
                                 ->where('floor_number', $this->floor_number);
                })->ignore($roomId)
            ],

            'floor_number' => ['required', 'integer', 'min:0'],
            'type'         => ['required', 'string', 'max:50'],
            'status'       => ['required', 'in:available,occupied,maintenance'],
            'description'  => ['nullable', 'string', 'max:500'],
            
            // إضافة حقول للقدرة الاستيعابية (اختياري لكنه مفيد أمنياً)
            'capacity'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * معالجة البيانات قبل التحقق (Sanitization)
     */
    protected function prepareForValidation()
    {
        $this->merge([
            // إزالة المسافات من رقم الغرفة لضمان دقة استعلام الـ Unique
            'room_number' => trim($this->room_number),
            // إذا كان المستخدم ليس HQ، نثبت فرعه تلقائياً لزيادة الأمان
            'branch_id'   => auth()->user()->hasRole('hq_admin') ? $this->branch_id : auth()->user()->branch_id,
            // تعيين حالة افتراضية إذا لم ترسل
            'status'      => $this->status ?? 'available',
        ]);
    }

    public function attributes(): array
    {
        return [
            'branch_id'    => 'الفرع',
            'room_number'  => 'رقم الغرفة',
            'floor_number' => 'رقم الطابق',
            'type'         => 'نوع الغرفة',
            'status'       => 'حالة الغرفة',
        ];
    }

    public function messages(): array
    {
        return [
            'room_number.unique' => 'رقم الغرفة :value مسجل مسبقاً في هذا الطابق بهذا الفرع.',
            'branch_id.exists'   => 'الفرع المختار غير صحيح.',
            'status.in'          => 'يرجى اختيار حالة غرفة صالحة (متاحة، مشغولة، صيانة).',
        ];
    }
}
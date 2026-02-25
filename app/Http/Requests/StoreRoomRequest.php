<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // التحقق من الصلاحية يتم عادة عبر الـ Policy في الـ Controller
        return true; 
    }

    public function rules(): array
    {
        // جلب معرف الغرفة في حالة التحديث لتجنب تعارض الـ Unique مع نفسه
        $roomId = $this->route('room') instanceof \App\Models\Room 
            ? $this->route('room')->id 
            : $this->route('room');

        return [
            // الفرع مطلوب دائماً لربط الغرفة بمكانها الصحيح
            'branch_id' => ['required', 'exists:branches,id'],
            
            // تصحيح منطق الـ Unique ليشمل الطابق والفرع مع استثناء السجل الحالي عند التحديث
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
            'status'       => ['nullable', 'in:available,occupied,maintenance'],
            'description'  => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * تعريب أسماء الحقول لرسائل خطأ احترافية
     */
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
            'room_number.unique' => 'رقم هذه الغرفة موجود بالفعل في هذا الطابق لهذا الفرع.',
            'branch_id.exists'   => 'الفرع المحدد غير موجود في سجلاتنا.',
            'floor_number.required' => 'يرجى تحديد الطابق لتنظيم خريطة الغرف.',
            'status.in'          => 'حالة الغرفة المختارة غير معرفة بالنظام.',
        ];
    }
}
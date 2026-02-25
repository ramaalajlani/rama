<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // يفضل التحقق من أن الحجز ليس "مكتمل" قبل السماح بأي تعديل
        $reservation = $this->route('reservation');
        return auth()->check() && (!$reservation || $reservation->status !== 'completed');
    }

    public function rules(): array
    {
        $reservation = $this->route('reservation');
        
        // جلب الحجز مع النزلاء للتحقق من وجود أي نزيل محظور ضمن المجموعة
        // نتحقق مما إذا كان أي شخص في الغرفة مدرج في القائمة السوداء
        $hasBlacklistedGuest = $reservation && $reservation->guests()
            ->where('status', 'blacklisted')
            ->exists();

        // نتحقق مما إذا كان الحجز مقفلاً إدارياً
        $isLocked = $reservation && $reservation->is_locked;

        return [
            // إذا وجد حظر أمني أو قفل إداري، نمنع تعديل التفاصيل اللوجستية
            'room_id'   => [($hasBlacklistedGuest || $isLocked) ? 'prohibited' : 'sometimes', 'exists:rooms,id'],
            'check_in'  => [($hasBlacklistedGuest || $isLocked) ? 'prohibited' : 'sometimes', 'date'],
            'check_out' => [($hasBlacklistedGuest || $isLocked) ? 'prohibited' : 'sometimes', 'date', 'after:check_in'],
            
            // الحالة: لا يمكن إلغاء الحجز إذا كان هناك نزيل محظور إلا عبر الـ HQ
            'status'    => [
                'sometimes', 
                'in:pending,confirmed,checked_out,cancelled',
                function ($attribute, $value, $fail) use ($hasBlacklistedGuest) {
                    if ($value === 'cancelled' && $hasBlacklistedGuest && !auth()->user()->hasRole('hq_admin')) {
                        $fail('لا يمكن إلغاء حجز لنزيل محظور أمنياً إلا من خلال الإدارة المركزية.');
                    }
                }
            ],

            // السبب إجباري عند وجود حظر أو قفل، أو عند تغيير الحالة لـ cancelled
            'reason'    => [
                ($hasBlacklistedGuest || $isLocked || $this->status === 'cancelled') ? 'required' : 'nullable',
                'string', 
                'min:10', 
                'max:500'
            ],
            
            'security_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required'    => 'عذراً، هذا الحجز مقفل أو مرتبط بنزيل "قيد التدقيق". لا يمكن التعديل بدون ذكر سبب إداري مفصل.',
            'room_id.prohibited' => 'التعديلات اللوجستية (الغرفة/الموعد) مجمدة لهذا الحجز لأسباب أمنية أو إدارية.',
            'reason.min'         => 'يرجى كتابة شرح وافٍ لسبب التعديل (10 حروف على الأقل).',
            'check_out.after'    => 'تاريخ المغادرة يجب أن يكون بعد تاريخ الدخول.',
        ];
    }
}
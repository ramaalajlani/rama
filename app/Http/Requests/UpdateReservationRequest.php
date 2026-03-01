<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        /** @var \App\Models\Reservation|null $reservation */
        $reservation = $this->route('reservation');

        $isLocked = (bool)($reservation?->is_locked ?? false);
        $isAudited = (($reservation?->audit_status ?? '') === 'audited');

        // إذا مقفل أو audited => ممنوع تعديل الحقول الحساسة
        $prohibit = ($isLocked || $isAudited) ? 'prohibited' : 'sometimes';

        return [
            // ✅ مطابق للـ migration + منطق القفل
            'room_id'   => [$prohibit, 'integer', 'exists:rooms,id'],

            'check_in'  => [$prohibit, 'date'],

            // ✅ check_out nullable بالمهاجرة
            'check_out' => [$prohibit, 'nullable', 'date', 'after:check_in'],

            // ✅ مطابق لقيم المهاجرة
            'status'    => [
                'sometimes',
                'string',
                Rule::in(['pending', 'confirmed', 'checked_out', 'cancelled']),
            ],

            // ✅ سبب التعديل مطلوب فقط إذا السجل مقفل/مدقق "وعم تبعت تعديل"
            // إذا بدك يكون إلزامي دائماً عند أي update للسجل المقفل، خلّيه required_without... (حسب UI)
            'audit_notes' => [
                ($isLocked || $isAudited) ? 'sometimes' : 'nullable',
                'nullable',
                'string',
                'min:10',
                'max:500',
            ],

            'security_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'vehicle_plate'  => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.prohibited'    => 'هذا السجل مقفل/مدقق ولا يمكن تعديل الغرفة.',
            'check_in.prohibited'   => 'هذا السجل مقفل/مدقق ولا يمكن تعديل تاريخ الدخول.',
            'check_out.prohibited'  => 'هذا السجل مقفل/مدقق ولا يمكن تعديل تاريخ الخروج.',
            'audit_notes.min'       => 'يرجى كتابة سبب واضح (10 أحرف على الأقل).',
            'check_out.after'       => 'تاريخ المغادرة يجب أن يكون بعد تاريخ الدخول.',
        ];
    }
}
<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReservationPolicy
{
    use HandlesAuthorization;

    /**
     * الفحص المبدئي: منع أي مستخدم معطل من الوصول للنظام
     */
    public function before(User $user, $ability)
    {
        if ($user->status !== 'active') {
            return false;
        }

        // المدير العام (HQ Admin) يملك صلاحية تجاوز أي قفل للحالات الطارئة
        if ($user->hasRole('hq_admin')) {
            return true;
        }
    }

    /**
     * من يمكنه رؤية قائمة الحجوزات
     */
    public function viewAny(User $user): bool
    {
        // الجميع يرى القائمة (لكن الـ Scope في الموديل سيحدد لكل فرع بياناته)
        return $user->hasAnyRole(['branch_reception', 'hq_auditor', 'hq_security', 'hq_supervisor']);
    }

    /**
     * من يمكنه رؤية تفاصيل حجز محدد
     */
    public function view(User $user, Reservation $reservation): bool
    {
        if ($user->hasAnyRole(['hq_auditor', 'hq_security', 'hq_supervisor'])) {
            return true;
        }

        // موظف الاستقبال يرى فقط حجوزات فرعه
        return $user->hasRole('branch_reception') && $user->branch_id === $reservation->branch_id;
    }

    /**
     * إنشاء حجز جديد
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    /**
     * تعديل الحجز (النقطة الأمنية الحساسة)
     */
    public function update(User $user, Reservation $reservation): bool
    {
        // 1. إذا كان الحجز مقفلاً أمنياً (Locked):
        // يمنع موظف الاستقبال تماماً من تغيير (رقم السيارة، رقم الغرفة، أو المرافقين)
        if ($reservation->is_locked) {
            return $user->hasAnyRole(['hq_security', 'hq_supervisor']);
        }

        // 2. فصل السلطات: المدقق والأمن للمراقبة فقط وليس لتغيير البيانات اللوجستية
        if ($user->hasAnyRole(['hq_auditor', 'hq_security'])) {
            return false;
        }

        // 3. موظف الاستقبال يعدل فقط في فرعه وإذا لم يكن هناك "قفل"
        if ($user->hasRole('branch_reception')) {
            return $user->branch_id === $reservation->branch_id;
        }

        return $user->hasAnyRole(['hq_supervisor']);
    }

    /**
     * القفل الأمني (Locking):
     * صلاحية سيادية للـ HQ لمنع التلاعب بالبيانات بعد الاشتباه بنزيل
     */
    public function lock(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_supervisor']);
    }

    /**
     * رؤية الوثائق (صور الهويات)
     */
    public function viewDocuments(User $user, Reservation $reservation): bool
    {
        // الأمن والمدقق لديهم صلاحية دائمة لرؤية الهويات
        if ($user->hasAnyRole(['hq_auditor', 'hq_security'])) {
            return true;
        }

        // موظف الاستقبال يراها فقط لفرعه بشرط عدم وجود قفل أمني
        return $user->hasRole('branch_reception') 
               && $user->branch_id === $reservation->branch_id 
               && !$reservation->is_locked;
    }

    /**
     * حذف السجل (ممنوع نهائياً للحفاظ على الأرشفة الأمنية)
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        return false; // المدير العام فقط من دالة before
    }

    /**
     * رؤية سجل العمليات (Audit Logs):
     * لمعرفة من عدل رقم السيارة أو وقت الدخول
     */
    public function viewAuditLogs(User $user): bool
    {
        return $user->hasAnyRole(['hq_auditor', 'hq_security']);
    }
}
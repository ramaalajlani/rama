<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReservationPolicy
{
    use HandlesAuthorization;

    /**
     * الفحص المبدئي: حماية النظام من المستخدمين المعطلين
     */
    public function before(User $user, $ability)
    {
        if ($user->status !== 'active') {
            return false;
        }

        // HQ Admin يملك صلاحية مطلقة (Root Access) للطوارئ
        if ($user->hasRole('hq_admin')) {
            return true;
        }
    }

    /**
     * رؤية القائمة: تشمل المدققين والأمن والموظفين
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_auditor', 'hq_security', 'hq_supervisor']);
    }

    /**
     * رؤية حجز محدد: عزل الفروع للموظفين وفتحها للـ HQ
     */
    public function view(User $user, Reservation $reservation): bool
    {
        if ($user->hasAnyRole(['hq_auditor', 'hq_security', 'hq_supervisor'])) {
            return true;
        }

        // الموظف يرى فقط بيانات فرعه
        return $user->hasRole('branch_reception') && $user->branch_id === $reservation->branch_id;
    }

    /**
     * إنشاء حجز: حصراً للاستقبال والمشرف
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    /**
     * التعديل: تطبيق سياسة القفل (البند 4 من الوثيقة)
     */
    public function update(User $user, Reservation $reservation): bool
    {
        // 1. إذا كان الحجز مقفلاً (is_locked) أو "تم تدقيقه" (audited)
        // يُمنع موظف الاستقبال منعاً باتاً من التعديل
        if ($reservation->is_locked || $reservation->audit_status === 'audited') {
            return $user->hasAnyRole(['hq_supervisor', 'hq_security']);
        }

        // 2. موظف الاستقبال يعدل فقط في فرعه وإذا لم يتم القفل
        if ($user->hasRole('branch_reception')) {
            return $user->branch_id === $reservation->branch_id;
        }

        // 3. المشرف يملك صلاحية التعديل دائماً (مع تسجيل السبب في السيرفس)
        return $user->hasAnyRole(['hq_supervisor']);
    }

    /**
     * التدقيق والقفل (Audit & Lock): البند 3 و 4 في الوثيقة
     * صلاحية سيادية للمشرفين والأمن فقط
     */
    public function audit(User $user): bool
    {
        return $user->hasAnyRole(['hq_supervisor', 'hq_security', 'hq_auditor']);
    }

    /**
     * رؤية الوثائق: الأمن والمدقق فوق القفل، الموظف تحت القفل
     */
    public function viewDocuments(User $user, Reservation $reservation): bool
    {
        if ($user->hasAnyRole(['hq_auditor', 'hq_security', 'hq_supervisor'])) {
            return true;
        }

        return $user->hasRole('branch_reception') 
               && $user->branch_id === $reservation->branch_id 
               && !$reservation->is_locked;
    }

    /**
     * الحذف: سياسة "لا حذف نهائي" (البند 8)
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        // المنع الافتراضي للجميع، والتعامل مع الأرشفة يتم عبر Soft Delete
        return false; 
    }

    /**
     * سجل التدقيق: حصراً للرقابة المركزية
     */
    public function viewAuditLogs(User $user): bool
    {
        return $user->hasAnyRole(['hq_auditor', 'hq_security', 'hq_supervisor']);
    }
}
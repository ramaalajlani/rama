<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GuestPolicy
{
    use HandlesAuthorization;

    /**
     * الفحص المبدئي (الأولوية القصوى)
     * يتم استدعاؤها قبل أي دالة أخرى
     */
    public function before(User $user, $ability)
    {
        // 1. إذا كان المستخدم معطلاً، ترفض أي عملية فوراً مهما كانت صلاحياته
        if (!$this->isUserActive($user)) {
            return false;
        }

        // 2. مدير النظام (hq_admin) له كامل الصلاحيات "الجوكر"
        if ($user->hasRole('hq_admin')) {
            return true;
        }
    }

    /**
     * تحقق داخلي من حالة نشاط الموظف
     */
    private function isUserActive(User $user): bool
    {
        return !empty($user->status) && strtolower(trim($user->status)) === 'active';
    }

    /**
     * رؤية قائمة النزلاء
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_auditor', 'hq_security', 'hq_supervisor']);
    }

    /**
     * رؤية ملف نزيل محدد
     */
    public function view(User $user, Guest $guest): bool
    {
        // ميزة أمنية: موظف الاستقبال يرى فقط النزلاء الذين زاروا فرعه (عزل البيانات)
        // إذا أردتِ تطبيق هذا القيد الصارم، يمكن تفعيل السطر التالي:
        // if ($user->hasRole('branch_reception')) { return $guest->reservations()->where('branch_id', $user->branch_id)->exists(); }

        return $user->hasAnyRole(['branch_reception', 'hq_auditor', 'hq_security', 'hq_supervisor']);
    }

    /**
     * إنشاء نزيل جديد
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    /**
     * التعديل على بيانات النزيل (منطق الحماية الثلاثي)
     */
    public function update(User $user, Guest $guest): bool
    {
        // 1. حماية المحظورين (Blacklist Protection)
        // لا يُسمح للاستقبال بتعديل ملف شخص محظور؛ الصلاحية للأمن فقط
        if ($guest->status === 'blacklisted') {
            return $user->hasAnyRole(['hq_security']);
        }

        // 2. حماية النزلاء المرصودين (Flagged)
        // إذا وُضع علم على النزيل، التعديل محصور بالمشرفين والأمن
        if ($guest->is_flagged) {
            return $user->hasAnyRole(['hq_security', 'hq_supervisor']);
        }

        // 3. قفل البيانات المعتمدة (Audit Lock)
        // بمجرد أن تصبح الحالة 'audited'، يُمنع الاستقبال من التغيير (لمنع التلاعب بالأسماء أو الهويات)
        if ($guest->audit_status === 'audited' && $user->hasRole('branch_reception')) {
            return false; 
        }

        // 4. الصلاحية العامة للتعديل
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    /**
     * تدقيق النزيل (Audit Action)
     * تحويل الحالة إلى Audited لقفل السجل
     */
    public function audit(User $user): bool
    {
        return $user->hasAnyRole(['hq_auditor', 'hq_supervisor']);
    }

    /**
     * الحذف (سياسة "لا حذف نهائي" في النظام الأمني)
     */
    public function delete(User $user, Guest $guest): bool
    {
        // ممنوع الحذف تماماً لأي دور، حتى لو بالخطأ
        // الاستثناء الوحيد هو hq_admin وتمت معالجته في دالة before
        return false;
    }
}
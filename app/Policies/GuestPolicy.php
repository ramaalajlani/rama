<?php

namespace App\Models; // تأكدي من المسار الصحيح
namespace App\Policies;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GuestPolicy
{
    use HandlesAuthorization;

    /**
     * الفحص المبدئي (الأولوية القصوى)
     */
    public function before(User $user, $ability)
    {
        // إذا كان المستخدم معطلاً، ترفض أي عملية فوراً
        if (!$this->isUserActive($user)) {
            return false;
        }

        // مدير النظام في HQ له كامل الصلاحيات دائماً
        if ($user->hasRole('hq_admin')) {
            return true;
        }
    }

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
     * التعديل على بيانات النزيل (النقطة الأمنية الأهم)
     */
    public function update(User $user, Guest $guest): bool
    {
        // 1. إذا كان النزيل محظوراً أمنياً (Blacklisted)
        // لا يُسمح للاستقبال بالاقتراب من الملف، الصلاحية فقط للأمن HQ
        if ($guest->status === 'blacklisted') {
            return $user->hasAnyRole(['hq_security']);
        }

        // 2. إذا كان النزيل مرصوداً (Flagged)
        // التعديل محصور بالمشرفين والأمن فقط
        if ($guest->is_flagged) {
            return $user->hasAnyRole(['hq_security', 'hq_supervisor']);
        }

        // 3. قفل البيانات بعد التدقيق (Audit Protection) - ميزة جديدة
        // إذا قمتِ في HQ بتدقيق النزيل (Audited)، يُمنع موظف الاستقبال من تغيير بياناته
        // لمنع تغيير (اسم الأم أو الأب) بعد اعتمادهم أمنياً
        if ($guest->audit_status === 'audited' && $user->hasRole('branch_reception')) {
            return false; 
        }

        // 4. الحالات العادية
        return $user->hasAnyRole(['branch_reception', 'hq_supervisor']);
    }

    /**
     * تدقيق النزيل (Audit Action)
     * صلاحية خاصة فقط للمدققين في HQ
     */
    public function audit(User $user): bool
    {
        return $user->hasAnyRole(['hq_auditor', 'hq_supervisor']);
    }

    /**
     * الحذف (محظور تماماً إلا للمدير العام)
     */
    public function delete(User $user, Guest $guest): bool
    {
        return false; // المدير العام مسموح له عبر دالة before
    }
}
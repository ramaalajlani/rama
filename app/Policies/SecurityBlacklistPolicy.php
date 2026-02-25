<?php

namespace App\Policies;

use App\Models\User;
use App\Models\SecurityBlacklist;
use Illuminate\Auth\Access\HandlesAuthorization;

class SecurityBlacklistPolicy
{
    use HandlesAuthorization;

    /**
     * القيد العام: لا يمكن لأي مستخدم معطل الوصول لبيانات الحظر
     */
    public function before(User $user, $ability)
    {
        if ($user->status !== 'active') {
            return false;
        }
    }

    /**
     * من يمكنه رؤية القائمة السوداء؟
     * (الأمن، المدير، والمدقق في HQ فقط)
     * يمنع موظف الاستقبال من رؤية القائمة لخصوصية البيانات ومنع تسريبها.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_admin', 'hq_auditor', 'hq_supervisor']);
    }

    /**
     * من يمكنه إضافة مطلوبين جدد؟
     * محصورة بالأمن والإدارة العليا لتجنب الإدراج الكيدي.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_admin']);
    }

    /**
     * من يمكنه تعديل بيانات الحظر (تغيير مستوى الخطورة أو التعليمات)؟
     */
    public function update(User $user, SecurityBlacklist $blacklist): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_admin']);
    }

    /**
     * صلاحية فحص النزلاء (Match Checking):
     * هذه الصلاحية يستخدمها النظام (Service) بالنيابة عن الموظف، 
     * لكننا نمنحها للأدوار التي تشرف على الفحص.
     */
    public function check(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_admin', 'branch_reception']);
    }

    /**
     * صلاحية التدقيق: مراجعة محاولات الاختراق التي تم صدها.
     */
    public function audit(User $user): bool
    {
        return $user->hasAnyRole(['hq_security', 'hq_auditor', 'hq_admin']);
    }

    /**
     * حذف السجل: (المدير العام فقط)
     * الحذف هنا أمني وحساس جداً.
     */
    public function delete(User $user, SecurityBlacklist $blacklist): bool
    {
        return $user->hasRole('hq_admin');
    }
}
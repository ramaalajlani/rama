<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Branch;

class BranchPolicy
{
    /**
     * من يمكنه رؤية قائمة الفروع؟
     * الإدارة المركزية والمدققون الأمنيون فقط في HQ
     */
    public function viewAny(User $user)
    {
        return $user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor', 'hq_security']);
    }

    /**
     * من يمكنه رؤية تفاصيل فرع محدد؟
     */
    public function view(User $user, Branch $branch)
    {
        // إذا كان المستخدم من HQ يرى أي فرع
        if ($user->hasAnyRole(['hq_admin', 'hq_supervisor', 'hq_auditor'])) {
            return true;
        }

        // موظف الاستقبال يرى فقط بيانات الفرع الذي يعمل فيه
        return $user->branch_id === $branch->id;
    }

    /**
     * من يمكنه إنشاء فروع جديدة؟
     * صلاحية سيادية للمدير العام في HQ فقط
     */
    public function create(User $user)
    {
        return $user->hasRole('hq_admin');
    }

    /**
     * من يمكنه تعديل بيانات فرع (تغيير الاسم، الموقع، الحالة)؟
     */
    public function update(User $user, Branch $branch)
    {
        return $user->hasRole('hq_admin');
    }

    /**
     * من يمكنه حذف فرع؟
     * عادة لا يتم الحذف بل التعطيل، وتمنح فقط للـ Admin
     */
    public function delete(User $user, Branch $branch)
    {
        return $user->hasRole('hq_admin');
    }
}
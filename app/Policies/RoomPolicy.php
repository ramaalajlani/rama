<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Room;
use Illuminate\Auth\Access\HandlesAuthorization;

class RoomPolicy
{
    use HandlesAuthorization;

    /**
     * من يمكنه رؤية قائمة الغرف؟
     * الجميع يمكنه الرؤية، ولكن الموديل (Global Scope) سيحدد لكل موظف فرعه فقط.
     */
    public function viewAny(User $user)
    {
        return $user->hasAnyRole(['branch_reception', 'hq_admin', 'hq_supervisor', 'hq_auditor']);
    }

    /**
     * من يمكنه إضافة غرف جديدة للنظام؟
     * حصرياً للإدارة المركزية لضبط سعة الفنادق.
     */
    public function create(User $user)
    {
        return $user->hasAnyRole(['hq_admin', 'hq_supervisor']);
    }

    /**
     * من يمكنه تعديل بيانات الغرفة (الرقم، الطابق، النوع)؟
     */
    public function update(User $user, Room $room)
    {
        // الإدارة المركزية تعدل أي شيء
        if ($user->hasAnyRole(['hq_admin', 'hq_supervisor'])) {
            return true;
        }

        // موظف الفرع يمكنه التعديل فقط في فرعه (بشرط عدم تغيير الهيكل الأساسي)
        return $user->branch_id === $room->branch_id && $user->hasRole('branch_reception');
    }

    /**
     * من يمكنه تغيير حالة الغرفة (متاحة، صيانة، مسكونة)؟
     * مهم جداً للرقابة الأمنية لمنع التسكين "تحت الطاولة"
     */
    public function changeStatus(User $user, Room $room)
    {
        // الإدارة المركزية لها الحق في إغلاق أي غرفة للصيانة الأمنية
        if ($user->hasAnyRole(['hq_admin', 'hq_security'])) {
            return true;
        }

        // موظف الفرع يغير الحالة فقط لغرف فرعه
        return $user->branch_id === $room->branch_id && $user->hasRole('branch_reception');
    }

    /**
     * من يمكنه حذف غرفة؟
     * ممنوع تماماً إلا للمدير العام (hq_admin)
     */
    public function delete(User $user, Room $room)
    {
        return $user->hasRole('hq_admin');
    }
}
<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class GuestService
{
    /**
     * معالجة النزيل: تسجيله أو تحديث بياناته مع الفحص الأمني
     */
    public function storeOrUpdateGuest(array $data)
    {
        // 1. توليد الهاش الأمني للهوية (للتعرف الصامت)
        $idHash = hash('sha256', $data['national_id']);

        // 2. الفحص الأمني المسبق في القائمة السوداء
        $blacklistMatch = SecurityBlacklist::where('national_id_hash', $idHash)->first();

        // 3. تحديد الحالة الأمنية للنزيل بناءً على الفحص
        if ($blacklistMatch) {
            $data['status'] = 'blacklisted';
            $data['is_flagged'] = true;
            
            // تسجيل محاولة دخول شخص محظور فوراً
            $this->createSecurityAlert($data, $blacklistMatch);
        }

        // 4. البحث عن النزيل في سجلاتنا السابقة لتجنب التكرار
        $guest = Guest::updateOrCreate(
            ['national_id' => $data['national_id']],
            [
                'full_name'   => $data['full_name'],
                'id_type'     => $data['id_type'],
                'phone'       => $data['phone'],
                'email'       => $data['email'] ?? null,
                'nationality' => $data['nationality'],
                'address'     => $data['address'] ?? null,
                'status'      => $data['status'] ?? 'active',
                'is_flagged'  => $data['is_flagged'] ?? false,
                'national_id_hash' => $idHash // تخزين الهاش للمطابقة المستقبلية
            ]
        );

        return $guest;
    }

    /**
     * إنشاء تنبيه أمني وإرساله للـ HQ
     */
    protected function createSecurityAlert(array $guestData, SecurityBlacklist $blacklistMatch)
    {
        SecurityNotification::create([
            'blacklist_id' => $blacklistMatch->id,
            'guest_name'   => $guestData['full_name'],
            'national_id'  => $guestData['national_id'],
            'branch_id'    => auth()->user()->branch_id,
            'risk_level'   => $blacklistMatch->risk_level,
            'details'      => "محاولة تسكين شخص مطلوب أمنياً في " . auth()->user()->branch->name,
            'read_at'      => null
        ]);

        // توثيق في سجلات النظام العميقة
        Log::warning("SECURITY ALERT: Blacklisted person detected", [
            'name' => $guestData['full_name'],
            'branch' => auth()->user()->branch_id
        ]);
    }
}
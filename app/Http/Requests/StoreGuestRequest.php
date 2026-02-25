<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use Illuminate\Support\Facades\{Log, DB};
use Exception;

class GuestService
{
    /**
     * معالجة النزيل: تسجيله أو تحديث بياناته مع الفحص الأمني المطور
     */
    public function storeOrUpdateGuest(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. تنظيف البيانات وتوليد الهاشات الأمنية
            $nationalId = trim($data['national_id']);
            $idHash = hash('sha256', $nationalId);
            
            // توليد هاش الاسم الكامل (Triple Check) مع إزالة المسافات وتوحيد حالة الأحرف
            $firstName = trim($data['first_name']);
            $fatherName = trim($data['father_name'] ?? '');
            $lastName = trim($data['last_name']);
            
            $fullName = "{$firstName} {$fatherName} {$lastName}";
            // الهاش الأمني يعتمد على الاسم ملتصقاً لمنع التلاعب بالمسافات
            $cleanName = mb_strtolower(str_replace(' ', '', $fullName), 'UTF-8');
            $fullSecurityHash = hash('sha256', $cleanName);

            // 2. الفحص الأمني المسبق (عبر الهوية أو هاش الاسم)
            $blacklistMatch = SecurityBlacklist::where('national_id_hash', $idHash)
                ->orWhere('full_security_hash', $fullSecurityHash)
                ->first();

            // 3. تحديد الحالة الأمنية
            if ($blacklistMatch) {
                $data['status'] = 'blacklisted';
                $data['is_flagged'] = true;
                
                // تسجيل التنبيه الأمني فوراً
                $this->createSecurityAlert($fullName, $nationalId, $blacklistMatch);
            }

            // 4. الحفظ أو التحديث
            $guest = Guest::updateOrCreate(
                ['national_id' => $nationalId],
                [
                    'first_name'         => $firstName,
                    'father_name'        => $fatherName,
                    'last_name'          => $lastName,
                    'mother_name'        => $data['mother_name'] ?? null,
                    'id_type'            => $data['id_type'],
                    'nationality'        => $data['nationality'],
                    'phone'              => $data['phone'],
                    'email'              => $data['email'] ?? null,
                    'address'            => $data['address'] ?? null,
                    'status'             => $data['status'] ?? 'active',
                    'is_flagged'         => $data['is_flagged'] ?? false,
                    'national_id_hash'   => $idHash,
                    'full_security_hash' => $fullSecurityHash,
                    'audit_status'       => 'new' 
                ]
            );

            return $guest;
        });
    }

    /**
     * البحث الذكي: تحسين الأداء باستخدام Select محدد
     */
    public function searchGuests(string $queryText)
    {
        $queryText = trim($queryText);
        return Guest::select('id', 'first_name', 'last_name', 'national_id', 'status', 'is_flagged', 'audit_status')
            ->where('national_id', 'like', "{$queryText}%") // البحث من البداية أسرع في الفهرسة
            ->orWhere('first_name', 'like', "%{$queryText}%")
            ->orWhere('last_name', 'like', "%{$queryText}%")
            ->limit(10)
            ->get();
    }

    /**
     * إنشاء تنبيه أمني وإرساله للـ HQ
     */
    protected function createSecurityAlert(string $fullName, string $nationalId, SecurityBlacklist $blacklistMatch)
    {
        try {
            $user = auth()->user();

            SecurityNotification::create([
                'blacklist_id' => $blacklistMatch->id,
                'guest_name'   => $fullName,
                'national_id'  => $nationalId,
                'branch_id'    => $user->branch_id ?? null,
                'risk_level'   => $blacklistMatch->risk_level ?? 'CRITICAL',
                'details'      => "🛑 محاولة رصد: تم العثور على مطابقة للقائمة السوداء لشخص يحاول التسكين باسم [{$fullName}] ورقم هوية [{$nationalId}]",
            ]);

            Log::warning("SECURITY_HOT: Blacklisted guest match detected", [
                'national_id' => $nationalId,
                'match_type'  => $blacklistMatch->full_security_hash === hash('sha256', mb_strtolower(str_replace(' ', '', $fullName))) ? 'NAME_MATCH' : 'ID_MATCH'
            ]);
        } catch (Exception $e) {
            Log::error("Failed to create security notification: " . $e->getMessage());
        }
    }
}
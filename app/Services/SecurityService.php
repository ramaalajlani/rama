<?php

namespace App\Services;

use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use Illuminate\Support\Facades\{Log, DB};
use Illuminate\Support\Str;

class SecurityService
{
    /**
     * تشفير البيانات الحساسة (SHA-256 مع Salt ونظام تطبيع لغوي)
     * تم تحسينه لمعالجة خصائص اللغة العربية لضمان عدم إفلات المشتبه بهم
     */
    public function generateHash(?string $value): ?string
    {
        if (empty($value)) return null;

        // 1. تنظيف أولي وإزالة المسافات
        $cleanValue = trim($value);
        $cleanValue = str_replace(' ', '', $cleanValue);

        // 2. تطبيع النصوص العربية (Arabic Normalization)
        // هذا الجزء يضمن أن "أحمد" و "احمد" ينتجان نفس الهاش
        $search  = ['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ', 'ء'];
        $replace = ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي', ''];
        $cleanValue = str_replace($search, $replace, $cleanValue);
        
        // 3. تحويل للأحرف الصغيرة للبيانات اللاتينية
        $cleanValue = Str::lower($cleanValue);
        
        // 4. استخدام مفتاح التطبيق كـ Salt لمنع هجمات التخمين
        $salt = config('app.key'); 
        
        return hash('sha256', $cleanValue . $salt);
    }

    /**
     * توليد مصفوفة الهاشات الأمنية (التشفير المتعدد)
     */
    public function generateSecurityHashes(array $data): array
    {
        $firstName  = $data['first_name'] ?? '';
        $fatherName = $data['father_name'] ?? '';
        $lastName   = $data['last_name'] ?? '';
        $motherName = $data['mother_name'] ?? '';

        return [
            // هاش الهوية: المعيار القطعي الأول
            'identity_hash'   => $this->generateHash($data['national_id'] ?? ''),

            // هاش الاسم الرباعي: الاسم + الأب + الكنية
            'full_name_hash'  => $this->generateHash($firstName . $fatherName . $lastName),

            // البصمة الثلاثية: (الاسم + الأب + الأم) - معيار أمني عالي الدقة (البند 5)
            'triple_check'    => $this->generateHash($firstName . $fatherName . $motherName),

            // البصمة الشاملة: (الكل مدمج)
            'full_hash'       => $this->generateHash($firstName . $fatherName . $lastName . $motherName),
        ];
    }

    /**
     * الفحص الأمني المتقاطع (Silent Cross-Check)
     */
    public function checkAgainstBlacklist(array $hashes): array
    {
        // البحث بالترتيب المنطقي للأهمية
        $match = SecurityBlacklist::where('is_active', true)
            ->where(function ($query) use ($hashes) {
                $query->where('identity_hash', $hashes['identity_hash'])
                      ->orWhere('triple_check_hash', $hashes['triple_check'])
                      ->orWhere('full_hash', $hashes['full_hash']);
            })
            ->first();

        return [
            'found'           => (bool)$match,
            'blacklist_entry' => $match
        ];
    }

    /**
     * تسجيل التنبيه في رادار HQ (رصد الموقع اللوجستي)
     */
    public function createSecurityAlert($match, $guest, $carPlate = 'N/A')
    {
        try {
            $user = auth()->user();
            
            // جلب تفاصيل الموقع الحالي للنزيل
            $reservation = $guest->reservations()->latest()->first();
            $locationInfo = ($reservation && $reservation->room) 
                ? "الغرفة: {$reservation->room->room_number} | الفرع: {$user->branch->name}" 
                : "في صالة الاستقبال";

            // إنشاء بلاغ أمني صامت يظهر فوراً في لوحة HQ
            return SecurityNotification::create([
                'blacklist_id'       => $match->id,
                'guest_id'           => $guest->id,
                'branch_id'          => $user->branch_id ?? null,
                'risk_level'         => $match->risk_level,
                'car_plate_captured' => $carPlate,
                'alert_message'      => "⚠️ تطابق أمني حرج - الموقع: {$locationInfo}",
                'instructions'       => $match->instructions ?? 'يرجى الهدوء وقفل الملف وإبلاغ العمليات فوراً.',
                'status'             => 'unread'
            ]);

        } catch (\Exception $e) {
            Log::error("SECURITY_ALERT_ERROR: " . $e->getMessage());
            return null;
        }
    }

    /**
     * إضافة هدف جديد للقائمة السوداء (HQ ONLY)
     */
    public function addToBlacklist(array $data)
    {
        $hashes = $this->generateSecurityHashes($data);

        return DB::transaction(function () use ($hashes, $data) {
            return SecurityBlacklist::create([
                'identity_hash'     => $hashes['identity_hash'],
                'full_name_hash'    => $hashes['full_name_hash'],
                'triple_check_hash' => $hashes['triple_check'],
                'full_hash'         => $hashes['full_hash'],
                'risk_level'        => $data['risk_level'] ?? 'CRITICAL',
                'reason'            => $data['reason'] ?? 'إدراج أمني مركزي',
                'instructions'      => $data['instructions'] ?? 'تنبيه: مطلوب مراجعة الفرع فوراً.',
                'is_active'         => true,
                'created_by'        => auth()->id(),
            ]);
        });
    }
}
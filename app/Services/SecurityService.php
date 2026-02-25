<?php

namespace App\Services;

use App\Models\SecurityBlacklist;
use App\Models\SecurityNotification;
use Illuminate\Support\Facades\{Log, DB};
use Illuminate\Support\Str;

class SecurityService
{
    /**
     * تشفير البيانات الحساسة (SHA-256 مع Salt ونظام تنظيف النصوص المتقدم)
     * تم تحسينه لمعالجة خصائص اللغة العربية (الهمزات، التاء المربوطة، إلخ)
     */
    public function generateHash(?string $value): ?string
    {
        if (empty($value)) return null;

        // 1. تنظيف أولي: إزالة المسافات الزائدة وتحويل حالة الأحرف (لغير العربية)
        $cleanValue = trim($value);
        $cleanValue = str_replace(' ', '', $cleanValue);

        // 2. تطبيع النصوص العربية (Arabic Normalization): لضمان تطابق الهاش مهما اختلف الرسم الإملائي
        $search  = ['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ'];
        $replace = ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي'];
        $cleanValue = str_replace($search, $replace, $cleanValue);
        
        // 3. تحويل للصغير لضمان العالمية
        $cleanValue = Str::lower($cleanValue);
        
        // 4. استخدام APP_KEY كـ Salt لزيادة التعقيد ومنع هجمات الجداول المسبقة
        $salt = config('app.key'); 
        
        return hash('sha256', $cleanValue . $salt);
    }

    /**
     * توليد مصفوفة الهاشات الأمنية (التشفير الثلاثي + البصمة الشاملة)
     */
    public function generateSecurityHashes(array $data): array
    {
        $firstName  = $data['first_name'] ?? '';
        $fatherName = $data['father_name'] ?? '';
        $lastName   = $data['last_name'] ?? '';
        $motherName = $data['mother_name'] ?? '';

        return [
            // 1. هاش الهوية (المعرف الفريد)
            'identity_hash'   => $this->generateHash($data['national_id'] ?? ''),

            // 2. هاش الاسم الرباعي (الاسم + الأب + الكنية)
            'full_name_hash'  => $this->generateHash($firstName . $fatherName . $lastName),

            // 3. هاش الأم المنفصل
            'mother_hash'     => $this->generateHash($motherName),

            // 4. البصمة الثلاثية: (الاسم + الأب + الأم) - معيار أمني عالي الدقة
            'triple_check'    => $this->generateHash($firstName . $fatherName . $motherName),

            // 5. البصمة الشاملة: (الكل مدمج) لضمان عدم الإفلات
            'full_hash'       => $this->generateHash($firstName . $fatherName . $lastName . $motherName),
        ];
    }

    /**
     * الفحص الأمني المركزي (Silent Cross-Check)
     */
    public function checkAgainstBlacklist(array $hashes): array
    {
        // البحث بالترتيب المنطقي: الهوية أولاً (قطعي)، ثم الثلاثي، ثم الشامل
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
     * تسجيل التنبيه في رادار HQ مع معلومات الموقع الجغرافي (الغرفة/الطابق)
     */
    public function createSecurityAlert($match, $guest, $carPlate = 'N/A')
    {
        try {
            $user = auth()->user();
            
            // محاولة جلب تفاصيل الحجز الحالي لرفع دقة بلاغ العمليات
            $reservation = $guest->reservations()->latest()->first();
            $locationInfo = ($reservation && $reservation->room) 
                ? "الغرفة: {$reservation->room->room_number} | الطابق: {$reservation->room->floor_number}" 
                : "قيد التسجيل في الاستقبال";

            return SecurityNotification::create([
                'blacklist_id'       => $match->id,
                'guest_id'           => $guest->id,
                'branch_id'          => $user->branch_id ?? null,
                'branch_name'        => $user->branch->name ?? 'المركز الرئيسي',
                'receptionist_name'  => $user->name, 
                'risk_level'         => $match->risk_level,
                'car_plate_captured' => $carPlate,
                'alert_message'      => "⚠️ تطابق أمني حرج - الموقع: {$locationInfo}",
                'instructions'       => $match->instructions ?? 'يرجى الهدوء وقفل الملف وإبلاغ العمليات.',
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
                'mother_name_hash'  => $hashes['mother_hash'],
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
<?php

namespace App\Services;

use App\Models\{Guest, GuestDocument, User, SecurityNotification};
use Illuminate\Support\Facades\{Cache, Log, DB, Storage};
use Illuminate\Support\Str;

class GuestService
{
    protected $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->securityService = $securityService;
    }

    /**
     * البحث المتقدم: يشمل البحث في الاسم الأول والأب والكنية والرقم الوطني
     */
    public function searchGuests(string $query)
    {
        return Guest::query()
            ->where('national_id', 'LIKE', "%{$query}%")
            ->orWhere('first_name', 'LIKE', "%{$query}%")
            ->orWhere('last_name', 'LIKE', "%{$query}%")
            ->orWhere('father_name', 'LIKE', "%{$query}%")
            ->limit(10)
            ->get();
    }

    /**
     * حفظ أو تحديث بيانات النزيل مع تطبيق الفحص الأمني والقيود الإدارية
     */
    public function storeOrUpdateGuest(array $data): Guest
    {
        return DB::transaction(function () use ($data) {
            // البحث عن النزيل بالرقم الوطني قبل أي تعديل
            $guest = Guest::where('national_id', $data['national_id'])->first();

            // 1. تحديد بيانات الهاش: إذا كان النزيل مدققاً (Audited)، نستخدم بياناته الأصلية لتوليد الهاش
            // لضمان عدم تلاعب الموظف بالأسماء لتجاوز الفحص الأمني
            $hashData = ($guest && $guest->audit_status === 'audited') 
                ? array_merge($data, [
                    'first_name'  => $guest->first_name,
                    'father_name' => $guest->father_name,
                    'last_name'   => $guest->last_name,
                    'mother_name' => $guest->mother_name
                ]) 
                : $data;

            // 2. توليد الهاشات الأمنية (الرقم الوطني + البصمة الثلاثية)
            $securityHashes = $this->securityService->generateSecurityHashes($hashData);

            // 3. الفحص المتقاطع مع القائمة السوداء (Blacklist Check)
            $matchResult = $this->securityService->checkAgainstBlacklist($securityHashes);

            // 4. حماية البيانات المعتمدة: منع تعديل الأسماء والهوية إذا كانت الحالة Audited
            if ($guest && $guest->audit_status === 'audited') {
                $data = array_diff_key($data, array_flip([
                    'first_name', 'father_name', 'last_name', 'mother_name', 'national_id'
                ]));
            }

            // 5. تحديث أو إنشاء سجل النزيل
            $guest = Guest::updateOrCreate(
                ['national_id' => $hashData['national_id']], // المفتاح الفريد الثابت
                array_merge($data, [
                    'national_id_hash'   => $securityHashes['identity_hash'],
                    'full_security_hash' => $securityHashes['full_hash'],
                    'is_flagged'         => $matchResult['found'] ? true : ($guest->is_flagged ?? false),
                    'status'             => $matchResult['found'] ? 'blacklisted' : ($guest->status ?? 'active'),
                    'audit_status'       => $guest ? $guest->audit_status : 'new',
                ])
            );

            // 6. إطلاق التنبيه الأمني فوراً في حال التطابق
            if ($matchResult['found']) {
                $this->triggerSecurityAlert($guest, $matchResult['blacklist_entry'], $data['car_plate'] ?? 'N/A');
            }

            Cache::forget('guests_list');
            return $guest;
        });
    }

    /**
     * إنشاء تنبيه أمني عالي الخطورة وإرساله للمراقبين
     */
    protected function triggerSecurityAlert(Guest $guest, $blacklistEntry, $carPlate)
    {
        $user = auth()->user();
        
        SecurityNotification::create([
            'blacklist_id'       => $blacklistEntry->id,
            'guest_id'           => $guest->id,
            'branch_name'        => $user->branch->name ?? 'المركز الرئيسي',
            'receptionist_name'  => $user->name,
            'car_plate_captured' => $carPlate,
            'risk_level'         => $blacklistEntry->risk_level,
            'alert_message'      => "🚨 خرق أمني: محاولة تسكين شخص مطلوب (تطابق بصمة الرقم الوطني أو الاسم الثلاثي)",
            'instructions'       => $blacklistEntry->instructions ?? 'يرجى إبلاغ السلطات فوراً وعدم إتمام الحجز.',
        ]);

        // توثيق في سجل الطوارئ بالسيرفر
        Log::channel('security')->emergency("CRITICAL_SECURITY_MATCH: ID {$guest->national_id} detected at Branch " . ($user->branch->id ?? 'HQ'));
    }

    /**
     * الأرشفة الأمنية للوثائق وتوليد بصمة رقمية للملف (Integrity Hash)
     */
    public function storeGuestDocument($guestId, $reservationId, $file)
    {
        // حساب الهاش للملف قبل رفعه لضمان عدم استبداله لاحقاً
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        // توليد اسم ملف أمني غير قابل للتخمين
        $fileName = "G_ID_{$guestId}_R_ID_{$reservationId}_TS_" . time() . '.' . $file->getClientOriginalExtension();
        
        // التخزين في المسار الخاص (Private Storage)
        $path = $file->storeAs("security/docs/{$guestId}", $fileName, 'private');

        return GuestDocument::create([
            'guest_id'       => $guestId,
            'reservation_id' => $reservationId,
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'file_hash'      => $fileHash,
            'mime_type'      => $file->getMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by'    => auth()->id(),
            'document_type'  => 'national_id' // النوع الافتراضي للوثيقة
        ]);
    }
}
<?php

namespace App\Services;

use App\Models\{Guest, GuestDocument, User, SecurityNotification};
use App\Services\SecurityService;
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
     * البحث المتقدم للنزلاء: يدعم البحث برقم الهوية أو الاسم الرباعي
     */
    public function searchGuests(string $query)
    {
        return Guest::query()
            ->where('national_id', $query)
            ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$query}%")
            ->limit(10)
            ->get();
    }

    /**
     * حفظ/تحديث النزيل مع تنفيذ "التشفير الثلاثي" والرصد اللحظي
     */
    public function storeOrUpdateGuest(array $data): Guest
    {
        return DB::transaction(function () use ($data) {
            // 1. البحث الأولي وتأمين السجل (Lock for update تمنع التعديل المتزامن)
            $guest = Guest::where('national_id', $data['national_id'])->lockForUpdate()->first();

            // 2. الحماية من التلاعب: إذا كان النزيل تم تدقيقه، نحذف الحقول الحساسة من المصفوفة لمنع تغييرها
            if ($guest && $guest->audit_status === 'audited') {
                $sensitiveFields = ['first_name', 'father_name', 'last_name', 'mother_name', 'national_id'];
                foreach ($sensitiveFields as $field) {
                    unset($data[$field]);
                }
            }

            // 3. توليد الهاشات الأمنية عبر SecurityService
            $securityHashes = $this->securityService->generateSecurityHashes($data);

            // 4. الفحص الأمني الصامت مقابل القائمة السوداء
            $matchResult = $this->securityService->checkAgainstBlacklist($securityHashes);

            // 5. حفظ البيانات: دمج البيانات الأصلية مع الهاشات والحالات الأمنية
            $guest = Guest::updateOrCreate(
                ['national_id' => $data['national_id'] ?? ($guest ? $guest->national_id : null)],
                array_merge($data, [
                    'national_id_hash'   => $securityHashes['identity_hash'] ?? ($guest ? $guest->national_id_hash : null),
                    'full_security_hash' => $securityHashes['full_hash'] ?? ($guest ? $guest->full_security_hash : null),
                    'is_flagged'         => $matchResult['found'] ?: ($guest->is_flagged ?? false),
                    'status'             => $matchResult['found'] ? 'blacklisted' : ($guest->status ?? 'active'),
                    'audit_status'       => $guest ? $guest->audit_status : 'new',
                ])
            );

            // 6. في حال وجود تطابق (HIT): إطلاق التنبيه
            if ($matchResult['found']) {
                $this->triggerSecurityAlert($guest, $matchResult['blacklist_entry'], $data['car_plate'] ?? 'N/A');
            }

            Cache::forget('guests_list');
            return $guest;
        });
    }

    /**
     * إرسال تنبيه فوري للـ HQ
     */
    protected function triggerSecurityAlert(Guest $guest, $blacklistEntry, $carPlate)
    {
        SecurityNotification::create([
            'blacklist_id'       => $blacklistEntry->id,
            'guest_id'           => $guest->id,
            'branch_name'        => auth()->user()->branch->name ?? 'Unknown Branch',
            'receptionist_name'  => auth()->user()->full_name,
            'car_plate_captured' => $carPlate,
            'risk_level'         => $blacklistEntry->risk_level,
            'alert_message'      => "تنبيه أمني: محاولة تسجيل نزيل مطابق للقائمة السوداء",
            'instructions'       => $blacklistEntry->instructions,
        ]);

        Log::channel('security')->emergency("CRITICAL_MATCH: ID {$guest->national_id} at branch " . auth()->user()->branch_id);
    }

    /**
     * تخزين وثائق النزيل مع بصمة SHA-256
     */
    public function storeGuestDocument($guestId, $reservationId, $file)
    {
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        $path = $file->storeAs(
            "security/docs/{$guestId}", 
            Str::random(40) . '.' . $file->getClientOriginalExtension(), 
            'private'
        );

        return GuestDocument::create([
            'guest_id'       => $guestId,
            'reservation_id' => $reservationId,
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'file_hash'      => $fileHash,
            'mime_type'      => $file->getMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by'    => auth()->id(),
        ]);
    }
}
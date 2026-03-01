<?php

namespace App\Services;

use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GuestService
{
    /**
     * إنشاء/تحديث نزيل حسب national_id
     * - يمنع تعديل الحقول الحساسة إذا كان audited
     * - يعتمد على Model Guest لتوليد national_id_hash و full_security_hash تلقائياً (saving)
     */
    public function storeOrUpdateGuest(array $data): Guest
    {
        return DB::transaction(function () use ($data) {

            // 1) Normalize national_id (منع duplicates بسبب مسافات/رموز)
            $nationalId = $this->normalizeNationalId($data['national_id'] ?? null);
            if ($nationalId === '') {
                throw new InvalidArgumentException('رقم الهوية مطلوب.');
            }

            // 2) تأكد من وجود الحقول الإلزامية عند الإنشاء (لو النزيل غير موجود)
            // (إذا عندك StoreGuestRequest قوي ممكن تشيل هالتحقق، بس بيفيد كسياج أمان)
            $requiredOnCreate = ['first_name','father_name','last_name','mother_name','id_type','nationality','phone'];
            foreach ($requiredOnCreate as $k) {
                // إذا الحقل غير موجود بالـ input، ما نكسر تحديث نزيل موجود audited
                // لكن إذا النزيل جديد لازم يكون موجود
                // نتحقق لاحقاً بعد معرفة وجود النزيل
            }

            // 3) قفل السجل لتجنب race condition
            $guest = Guest::query()
                ->where('national_id', $nationalId)
                ->lockForUpdate()
                ->first();

            // 4) لو النزيل جديد: لازم نتأكد من required fields
            if (!$guest) {
                foreach (['first_name','father_name','last_name','mother_name','id_type','nationality','phone'] as $k) {
                    $v = trim((string)($data[$k] ?? ''));
                    if ($v === '') {
                        throw new InvalidArgumentException("الحقل مطلوب: {$k}");
                    }
                }
            }

            // 5) تنظيف الحقول النصية الأساسية
            $data = $this->sanitizeGuestPayload($data);

            // 6) enforced national_id (دائماً)
            $data['national_id'] = $nationalId;

            // 7) إذا audited: امنع تعديل الهوية والأسماء
            if ($guest && ($guest->audit_status ?? '') === 'audited') {

                // نسمح فقط بتحديث بيانات التواصل (حسب ما بدك)
                $allowed = [
                    'phone',
                    'email',
                    'car_plate',
                ];

                $data = array_intersect_key($data, array_flip($allowed));

                // (اختياري) لا تغيّر status/is_flagged من الفرع
                // إذا بدك HQ فقط هو اللي يغيرهم:
                // unset($data['status'], $data['is_flagged']);
            }

            // 8) تحديث أو إنشاء
            // - لا تستخدم insert() لأنه يتجاوز events
            return Guest::updateOrCreate(
                ['national_id' => $nationalId],
                $data
            );
        });
    }

    private function normalizeNationalId($value): string
    {
        $v = trim((string)$value);
        // احذف المسافات الداخلية
        $v = preg_replace('/\s+/', '', $v) ?? '';
        return $v;
    }

    private function sanitizeGuestPayload(array $data): array
    {
        $trimKeys = [
            'first_name','father_name','last_name','mother_name',
            'id_type','nationality','phone','email','car_plate',
        ];

        foreach ($trimKeys as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = trim((string)$data[$k]);
                if ($data[$k] === '') $data[$k] = null; // خلي الفارغ null
            }
        }

        // قواعد إضافية بسيطة
        if (isset($data['email']) && $data['email'] !== null) {
            $data['email'] = mb_strtolower((string)$data['email'], 'UTF-8');
        }

        return $data;
    }
}
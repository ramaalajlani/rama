<?php

namespace App\Services;

use App\Models\{SecurityBlacklist, SecurityNotification, Guest, Reservation};
use Illuminate\Support\Facades\{Auth, DB, Gate};
use Illuminate\Support\Str;
use RuntimeException;

class SecurityService
{
    private function normalize(?string $value): string
    {
        $v = trim((string)$value);

        // بدل حذف كل المسافات: وحّدها ثم احذفها فقط لو بدك تطابق “مشدد”
        // حالياً نخليها بدون مسافات لثبات الهاش
        $v = preg_replace('/\s+/u', ' ', $v ?? '');
        $v = str_replace(' ', '', $v);

        $search  = ['أ','إ','آ','ة','ى','ؤ','ئ','ء'];
        $replace = ['ا','ا','ا','ه','ي','و','ي',''];
        $v = str_replace($search, $replace, $v);

        return Str::lower($v);
    }

    private function hash(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;

        // app.key فيه prefix base64:... أحياناً، لكنه ثابت -> OK كسالت
        $salt = (string) config('app.key');
        return hash('sha256', $this->normalize($value) . $salt);
    }

    /**
     * يبني كل الهاشات اللازمة لتعبئة security_blacklists
     * ويعطيك مفاتيح بأسماء الأعمدة مباشرة.
     */
    public function buildHashes(array $data): array
    {
        $fn  = (string)($data['first_name'] ?? '');
        $fa  = (string)($data['father_name'] ?? '');
        $ln  = (string)($data['last_name'] ?? '');
        $mn  = (string)($data['mother_name'] ?? '');
        $nid = (string)($data['national_id'] ?? '');

        return [
            // DB columns:
            'identity_hash'      => $this->hash($nid),
            'full_name_hash'     => $this->hash($fn . $ln),     // first+last
            'father_name_hash'   => $this->hash($fa),
            'mother_name_hash'   => $this->hash($mn),

            // “triple check” = first + father + mother (حسب تصميمك)
            'triple_check_hash'  => $this->hash($fn . $fa . $mn),

            // full hash = first + father + last + mother
            'full_hash'          => $this->hash($fn . $fa . $ln . $mn),
        ];
    }

    /**
     * إضافة سجل للقائمة السوداء (HQ)
     * - يعتمد على identity_hash unique لمنع التكرار
     * - يكتب بقية الهاشات للفهرسة والمطابقة
     */
    public function addToBlacklist(array $payload): SecurityBlacklist
    {
        // enforce policy
        Gate::authorize('create', SecurityBlacklist::class);

        $hashes = $this->buildHashes($payload);

        if (empty($hashes['identity_hash'])) {
            throw new RuntimeException('تعذر توليد بصمة الهوية.');
        }

        return DB::transaction(function () use ($payload, $hashes) {

            // إذا موجود مسبقاً حسب identity_hash: رجّعه بدل error
            $existing = SecurityBlacklist::query()
                ->where('identity_hash', $hashes['identity_hash'])
                ->first();

            if ($existing) {
                // optionally update risk_level/reason/instructions
                $existing->update([
                    'risk_level'    => $payload['risk_level'] ?? $existing->risk_level,
                    'reason'        => $payload['reason'] ?? $existing->reason,
                    'instructions'  => $payload['instructions'] ?? $existing->instructions,
                    'is_active'     => true,
                ]);

                return $existing->fresh();
            }

            return SecurityBlacklist::create([
                'identity_hash'      => $hashes['identity_hash'],
                'full_name_hash'     => $hashes['full_name_hash'],
                'father_name_hash'   => $hashes['father_name_hash'],
                'mother_name_hash'   => $hashes['mother_name_hash'],
                'triple_check_hash'  => $hashes['triple_check_hash'],
                'full_hash'          => $hashes['full_hash'],

                'risk_level'         => $payload['risk_level'] ?? 'WATCHLIST',
                'reason'             => $payload['reason'] ?? null,
                'instructions'       => $payload['instructions'] ?? null,

                'created_by'         => Auth::id(),
                'is_active'          => true,
            ]);
        });
    }

    /**
     * فحص صامت ضد blacklist
     * - ينشئ Notification مرة واحدة فقط
     * - لا يرجع أي تفاصيل حساسة
     */
    public function checkGuestAgainstBlacklist(Guest $guest, Reservation $reservation): array
    {
        Gate::authorize('check', SecurityBlacklist::class);

        $hashes = $this->buildHashes([
            'first_name'  => $guest->first_name,
            'father_name' => $guest->father_name,
            'last_name'   => $guest->last_name,
            'mother_name' => $guest->mother_name,
            'national_id' => $guest->national_id,
        ]);

        $match = SecurityBlacklist::query()
            ->where('is_active', true)
            ->where(function ($q) use ($hashes) {
                $q->where('identity_hash', $hashes['identity_hash'])
                  ->orWhere('triple_check_hash', $hashes['triple_check_hash'])
                  ->orWhere('full_hash', $hashes['full_hash']);
            })
            ->first();

        if (!$match) {
            return ['found' => false];
        }

        $existing = SecurityNotification::query()
            ->where('guest_id', $guest->id)
            ->where('reservation_id', $reservation->id)
            ->where('blacklist_id', $match->id)
            ->exists();

        if (!$existing) {
            $u = auth()->user();

            SecurityNotification::create([
                'blacklist_id'       => $match->id,
                'guest_id'           => $guest->id,
                'reservation_id'     => $reservation->id,
                'branch_name'        => $u->branch?->name ?? 'HQ',
                'receptionist_name'  => $u->name,
                'car_plate_captured' => $reservation->vehicle_plate,
                'risk_level'         => $match->risk_level ?? 'WATCHLIST',
                'alert_message'      => 'تنبيه أمني: تم رصد تطابق يحتاج مراجعة مركزية.',
                'instructions'       => $match->instructions,
            ]);
        }

        return ['found' => true];
    }
}
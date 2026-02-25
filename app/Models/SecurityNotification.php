<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecurityNotification extends Model
{
    use HasFactory;

    protected $table = 'security_notifications';

    protected $fillable = [
        'blacklist_id',
        'guest_id',           // ربط مباشر بالنزيل المشبوه
        'reservation_id',     // ربط بالحجز الذي تمت فيه المحاولة
        'branch_name',        // الفرع الذي رصد المحاولة
        'receptionist_name',  // الموظف المواجه للنزيل
        'car_plate_captured', // رقم السيارة التي رُصدت في هذه اللحظة (Snapshot)
        'risk_level',         // مستوى الخطورة وقت التنبيه
        'alert_message',      // تفاصيل التنبيه (مثلاً: تطابق بصمة الاسم الثلاثي)
        'instructions',       // التعليمات التي أُعطيت للموظف آلياً
        'read_at',            // وقت اطلاعك على الإشعار في HQ
        'read_by'             // من هو المدقق الذي عالج الإشعار
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships) - لعرض "كل شيء" في لوحة التحكم
    |--------------------------------------------------------------------------
    */

    /**
     * جلب بيانات الشخص من القائمة السوداء (لمعرفة سبب الحظر الأصلي)
     */
    public function blacklist(): BelongsTo
    {
        return $this->belongsTo(SecurityBlacklist::class, 'blacklist_id');
    }

    /**
     * جلب ملف النزيل الحالي (لمقارنة بياناته الحالية بما هو مسجل في البلاك ليست)
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    /**
     * جلب بيانات الحجز (لمعرفة الغرفة والتوقيت)
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * المدقق من الـ HQ الذي اطلع على التنبيه وعالجه
     */
    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /*
    |--------------------------------------------------------------------------
    | وظائف مساعدة (Helpers)
    |--------------------------------------------------------------------------
    */

    /**
     * هل تم الاطلاع على هذا الإشعار من قبل الإدارة؟
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }
}
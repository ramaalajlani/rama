<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SecurityBlacklist extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_blacklists';

    protected $fillable = [
        // 1. بصمات الهوية المشفرة
        'identity_hash',      // هاش رقم الهوية (Unique ID)

        // 2. بصمات الأسماء المفصلة (للمطابقة الثلاثية)
        'full_name_hash',     // هاش الاسم الكامل واللقب
        'father_name_hash',   // هاش اسم الأب منفصلاً
        'mother_name_hash',   // هاش اسم الأم منفصلاً
        'triple_check_hash',  // هاش مدمج (الاسم + الأب + الأم) - الأقوى أمنياً

        // 3. تصنيفات المخاطر
        'risk_level',         // WATCHLIST (مراقبة), DANGER (خطر), BANNED (محظور قطعي)
        'reason',             // سبب الإدراج (يظهر للمدقق فقط)
        'instructions',       // تعليمات لموظف الاستقبال (مثلاً: اتصل بالشرطة فوراً)
        'is_active',          // حالة القيد (فعال/معطل)
        'created_by',         // المسؤول الذي أضاف السجل من الـ HQ
    ];

    /**
     * إخفاء الهاشات من الـ API لزيادة الأمان
     */
    protected $hidden = [
        'identity_hash',
        'full_name_hash',
        'father_name_hash',
        'mother_name_hash',
        'triple_check_hash',
    ];

    /**
     * إعدادات سجل التدقيق (Audit Log)
     * يتم تسجيل التغييرات في مستوى الخطر أو التعليمات لضمان رقابة HQ
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['risk_level', 'is_active', 'reason', 'instructions'])
            ->logOnlyDirty()
            ->useLogName('security_monitor') // توحيد السجل مع النظام الأمني المركزي
            ->setDescriptionForEvent(fn(string $eventName) => "إدارة القوائم السوداء: تمت عملية {$eventName} على سجل محظور أمنياً");
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    /**
     * المسؤول (HQ Admin) الذي قام بإضافة هذا الشخص للقائمة
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | النطاقات الأمنية (Scopes)
    |--------------------------------------------------------------------------
    */

    // جلب القيود النشطة فقط عند إجراء عملية الفحص
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // جلب القيود عالية الخطورة فقط
    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['DANGER', 'BANNED']);
    }
}
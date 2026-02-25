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
        'identity_hash', 
        'full_name_hash',
        'father_name_hash',
        'mother_name_hash',
        'triple_check_hash',
        'risk_level',
        'reason',
        'instructions',
        'is_active',
        'created_by',
    ];

    /**
     * 1. تحسين الـ JSON Response:
     * أضفت 'creator' للمخفيات لضمان عدم تسريب بيانات المسؤول بشكل تلقائي 
     * عند فحص القائمة السوداء، مما يسرع عملية المطابقة الأمنية.
     */
    protected $hidden = [
        'identity_hash',
        'full_name_hash',
        'father_name_hash',
        'mother_name_hash',
        'triple_check_hash',
        'creator', // إخفاء العلاقة لمنع الدوران اللانهائي
    ];

    /**
     * إعدادات سجل التدقيق (Audit Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['risk_level', 'is_active', 'reason', 'instructions'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs() // إضافة لمنع السجلات الفارغة التي تملأ قاعدة البيانات
            ->useLogName('security_monitor')
            ->setDescriptionForEvent(fn(string $eventName) => "إدارة القوائم السوداء: تمت عملية {$eventName} على سجل محظور أمنياً - المعرف: #{$this->id}");
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['DANGER', 'BANNED']);
    }
}
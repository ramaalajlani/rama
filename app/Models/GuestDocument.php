<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage; 
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestDocument extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'guest_documents';

    protected $fillable = [
        'reservation_id', 
        'guest_id',      
        'document_type',
        'file_path',
        'file_name',      
        'file_hash', 
        'mime_type',      
        'file_size',
        'uploaded_by' 
    ];

    /**
     * 1. الحقول المخفية:
     * نمنع تحميل بيانات النزيل والحجز بشكل JSON تلقائي داخل الوثيقة
     * لتجنب الحلقات الدائرية (Infinite Loops) التي تعطل XAMPP.
     */
    protected $hidden = [
        'guest',
        'reservation',
        'file_path' // حماية أمنية للمسار الحقيقي على السيرفر
    ];

    /**
     * إعدادات سجل النشاط للوثائق (Audit Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'document_type', 
                'file_name', 
                'file_hash', 
                'guest_id',
                'reservation_id'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor') 
            ->setDescriptionForEvent(function(string $eventName) {
                /**
                 * 2. تحسين الأداء في السجل:
                 * استخدام relationLoaded يمنع السيرفر من تنفيذ استعلام SQL جديد 
                 * في كل مرة يتم فيها تسجيل نشاط، مما يسرع عملية الرفع.
                 */
                $guestName = $this->relationLoaded('guest') ? 
                    "{$this->guest->first_name} {$this->guest->last_name}" : 
                    "رقم: {$this->guest_id}";
                
                return "وثائق أمنية: تم {$eventName} ملف للنزيل: {$guestName} ضمن الحجز: #{$this->reservation_id}";
            });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /*
    |--------------------------------------------------------------------------
    | الوظائف الأمنية (Security Functions)
    |--------------------------------------------------------------------------
    */

    public function isIntegrityValid(): bool
    {
        // استخدام التخزين الخاص لضمان الأمان
        if (!Storage::disk('private')->exists($this->file_path)) {
            return false;
        }

        $currentHash = hash_file('sha256', Storage::disk('private')->path($this->file_path));
        
        return $this->file_hash === $currentHash;
    }
}
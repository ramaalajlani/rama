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
     * إعدادات سجل النشاط للوثائق (Audit Log)
     * تم تحسين الوصف ليعرض الاسم الكامل للنزيل عند حدوث أي إجراء
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
                // محاولة جلب اسم النزيل للعرض في السجل
                $name = $this->guest ? 
                        "{$this->guest->first_name} {$this->guest->father_name} {$this->guest->last_name}" : 
                        "رقم النزيل: {$this->guest_id}";
                
                return "وثائق أمنية: تم {$eventName} ملف إثبات شخصية للنزيل: {$name} ضمن الحجز رقم: #{$this->reservation_id}";
            });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    /**
     * النزيل صاحب الوثيقة
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    /**
     * الحجز المرتبط به هذه الوثيقة
     * تم التعديل ليطابق اسم الموديل Reservation الذي اعتمدناه في الـ Controller والـ Service
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /**
     * الموظف الذي قام برفع الملف
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /*
    |--------------------------------------------------------------------------
    | الوظائف الأمنية (Security Functions)
    |--------------------------------------------------------------------------
    */

    /**
     * التحقق من سلامة الملف برمجياً (Security Integrity Check)
     * تقارن الـ Hash المخزن بالـ Hash الفعلي للملف لمنع التلاعب بالصور في السيرفر
     */
    public function isIntegrityValid(): bool
    {
        // استخدام التخزين الخاص (Private) لضمان عدم الوصول للملفات عبر رابط مباشر
        // تم التأكد من مسار التخزين الصحيح
        if (!Storage::disk('private')->exists($this->file_path)) {
            return false;
        }

        // حساب بصمة الملف الحالية ومقارنتها بالبصمة وقت الرفع
        $currentHash = hash_file('sha256', Storage::disk('private')->path($this->file_path));
        
        return $this->file_hash === $currentHash;
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; 
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Guest extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'guests';

    protected $fillable = [
        'first_name', 
        'father_name', 
        'last_name', 
        'mother_name',
        'national_id',
        'id_type',
        'nationality',
        'phone',
        'email',
        'address',
        'car_plate', 
        'national_id_hash',
        'full_security_hash',
        'audit_status', 
        'audited_at', 
        'audited_by', 
        'audit_notes',
        'is_flagged', 
        'status', // active, blacklisted, suspended
    ];

    // لضمان ظهور الحقول المحسوبة في JSON عند إرسالها للفرونت إند
    protected $appends = ['full_name'];

    /**
     * إعدادات سجل التدقيق (Audit Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name', 'father_name', 'last_name', 'national_id', 
                'is_flagged', 'status', 'audit_status'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor');
    }

    /*
    |--------------------------------------------------------------------------
    | الوصول (Accessors)
    |--------------------------------------------------------------------------
    */

    // دمج الاسم الثلاثي لسهولة العرض في JS
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->father_name} {$this->last_name}";
    }

    /*
    |--------------------------------------------------------------------------
    | النطاقات الأمنية (Scopes)
    |--------------------------------------------------------------------------
    */

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    public function scopePendingAudit($query)
    {
        return $query->where('audit_status', 'new');
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    /**
     * العلاقة التي سببت الخطأ 500
     * يجب أن تشير إلى موديل Reservation (وليس GuestReservation إلا إذا كان الملف موجوداً بهذا الاسم)
     */
    public function reservations(): BelongsToMany
    {
        // تم تغيير GuestReservation.class إلى Reservation.class كاسم قياسي
        return $this->belongsToMany(Reservation::class, 'reservation_guest', 'guest_id', 'reservation_id')
                    ->withPivot(['participant_type', 'vehicle_plate_at_checkin']) 
                    ->withTimestamps();
    }

    /**
     * علاقة جلب أحدث حجز (المطلوبة في GuestController->index)
     */
    public function latestReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)->latestOfMany();
    }

    /**
     * المدقق (User) الذي راجع البيانات
     */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    /**
     * الوثائق الأمنية
     */
    public function personalDocuments(): HasMany
    {
        return $this->hasMany(GuestDocument::class, 'guest_id');
    }
}
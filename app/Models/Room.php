<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Room extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'rooms';

    protected $fillable = [
        'branch_id',    // ربط الغرفة بفرع محدد
        'room_number',  // رقم الغرفة (معرف بصري)
        'floor_number', // رقم الطابق لتسهيل التفتيش الأمني
        'type',         // نوع الغرفة (فردية، مزدوجة، جناح)
        'status',       // الحالات: available (متاحة)، occupied (مسكونة)، maintenance (صيانة أمنية/فنية)
        'description'   // ملاحظات إضافية حول موقع الغرفة أو ميزاتها
    ];

    /**
     * 1. الحقول المخفية (Hidden):
     * نمنع تحميل العلاقات بشكل تلقائي عند تحويل الموديل إلى JSON
     * لكسر أي حلقة دوران مع الحجوزات أو الفروع.
     */
    protected $hidden = [
        'reservations',
        'branch',
        'deleted_at'
    ];

    /**
     * إعدادات سجل التدقيق (Audit Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['room_number', 'floor_number', 'status', 'branch_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor') 
            ->setDescriptionForEvent(function(string $eventName) {
                return "إدارة المرافق: تم {$eventName} بيانات الغرفة {$this->room_number} (الطابق: {$this->floor_number})";
            });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'room_id');
    }

    /*
    |--------------------------------------------------------------------------
    | النطاقات (Scopes)
    |--------------------------------------------------------------------------
    */

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeInMaintenance($query)
    {
        return $query->where('status', 'maintenance');
    }

    public function scopeOccupied($query)
    {
        return $query->where('status', 'occupied');
    }
}
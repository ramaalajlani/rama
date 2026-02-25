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
     * إعدادات سجل التدقيق (Audit Log)
     * ضروري جداً للـ HQ لمراقبة "حركة الغرف" ومنع التسكين غير الرسمي
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['room_number', 'floor_number', 'status', 'branch_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor') // توحيد السجل مع النظام الأمني المركزي
            ->setDescriptionForEvent(function(string $eventName) {
                return "إدارة المرافق: تم {$eventName} بيانات الغرفة {$this->room_number} (الطابق: {$this->floor_number})";
            });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    /**
     * الفرع التابعة له الغرفة
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * الحجوزات التاريخية والنشطة المرتبطة بهذه الغرفة
     * ملاحظة: تم التأكد من اسم الموديل Reservation كما اعتمدناه سابقاً
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'room_id');
    }

    /*
    |--------------------------------------------------------------------------
    | النطاقات الأمنية (Scopes) - لتسهيل عرض حالة الفندق في HQ
    |--------------------------------------------------------------------------
    */

    // الغرف الجاهزة لاستقبال نزلاء
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    // الغرف التي تحت الصيانة (مغلقة أمنياً أو فنياً)
    public function scopeInMaintenance($query)
    {
        return $query->where('status', 'maintenance');
    }

    // الغرف المسكونة حالياً (تظهر لكِ من يسكن في أي طابق)
    public function scopeOccupied($query)
    {
        return $query->where('status', 'occupied');
    }
}
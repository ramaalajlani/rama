<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
// استيراد أدوات الرقابة من Spatie
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Branch extends Model
{
    // استبدال Observable بـ LogsActivity لتوحيد نظام الرقابة
    use SoftDeletes, HasFactory, LogsActivity;

    protected $fillable = ['name', 'address', 'phone', 'status']; 

    /**
     * إعدادات سجل النشاط للفروع
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'address', 'phone']) // مراقبة أي تغيير في بيانات الفرع
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('branch_management')
            ->setDescriptionForEvent(fn(string $eventName) => "تمت عملية {$eventName} على الفرع: {$this->name}");
    }

    /**
     * الموظفون المرتبطون بهذا الفرع
     */
    public function users(): HasMany
    {
        // ملاحظة: حسب الكود السابق في موديل User، العلاقة هي BelongsTo 
        // لذا هنا نستخدم HasMany للوصول لموظفي الفرع
        return $this->hasMany(User::class);
    }

    /**
     * الغرف التابعة لهذا الفرع
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * الحجوزات التابعة لهذا الفرع
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
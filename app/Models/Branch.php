<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Branch extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $fillable = ['name', 'address', 'phone', 'status'];

    /**
     * الحقول المخفية عند تحويل الموديل إلى JSON.
     * إضافة هذه الحقول تمنع حدوث الدوران اللانهائي (Circular Reference) 
     * وتسرع استجابة الـ API بشكل كبير.
     */
    protected $hidden = [
        'users',
        'rooms',
        'reservations',
        'deleted_at'
    ];

    /**
     * إعدادات سجل النشاط للفروع (Spatie Activitylog)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'address', 'phone'])
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
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Reservation extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    // اسم الجدول في قاعدة البيانات
    protected $table = 'guest_reservations';

    protected $fillable = [
        'room_id', 
        'branch_id', 
        'user_id', 
        'check_in', 
        'check_out', 
        'is_locked', 
        'locked_by', 
        'status', 
        'security_notes',
        'vehicle_plate' 
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
    ];

    // إضافة الوصول السريع للنزيل الأساسي عند تحويل الموديل لـ JSON
    protected $appends = ['primary_guest'];

    /**
     * إعدادات سجل التدقيق (Audit Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 
                'is_locked', 
                'locked_by', 
                'room_id', 
                'check_in', 
                'check_out', 
                'security_notes',
                'vehicle_plate'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor') 
            ->setDescriptionForEvent(fn(string $eventName) => "تحرك أمني: تم إجراء عملية {$eventName} على سجل الإقامة رقم #{$this->id}");
    }

    /**
     * منطق التحكم التلقائي (Booted Strategy)
     */
    protected static function booted()
    {
        // النطاق العالمي: العزل الأمني بين الفروع (الموظف يرى بيانات فرعه فقط)
        static::addGlobalScope('branch_access', function (Builder $builder) {
            if (Auth::check() && !app()->runningInConsole()) {
                $user = Auth::user();
                if (!$user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor'])) {
                    $builder->where($builder->getQuery()->from . '.branch_id', $user->branch_id);
                }
            }
        });

        // تعبئة البيانات تلقائياً عند الإنشاء
        static::creating(function ($reservation) {
            if (Auth::check()) {
                $user = Auth::user();
                if (!$user->hasAnyRole(['hq_admin', 'hq_supervisor'])) {
                    $reservation->branch_id = $reservation->branch_id ?? $user->branch_id;
                }
                $reservation->user_id = $reservation->user_id ?? $user->id;
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    /**
     * النزلاء المسجلين في هذا الحجز
     * تم التعديل ليطابق اسم جدول الـ Migration: reservation_guest
     */
    public function occupants(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guest', 'reservation_id', 'guest_id')
                    ->withPivot([
                        'participant_type', 
                        'vehicle_plate_at_checkin', 
                        'registered_by' // الحقل الرقابي الجديد
                    ])
                    ->withTimestamps();
    }

    /**
     * علاقة بديلة (Alias) لمنع أخطاء Call to undefined method guests()
     */
    public function guests(): BelongsToMany
    {
        return $this->occupants();
    }

    public function room(): BelongsTo 
    { 
        return $this->belongsTo(Room::class); 
    }
    
    public function branch(): BelongsTo 
    { 
        return $this->belongsTo(Branch::class); 
    }
    
    public function creator(): BelongsTo 
    { 
        return $this->belongsTo(User::class, 'user_id'); 
    }
    
    public function locker(): BelongsTo 
    { 
        return $this->belongsTo(User::class, 'locked_by'); 
    }
    
    public function documents(): HasMany 
    { 
        return $this->hasMany(GuestDocument::class, 'reservation_id'); 
    }

    /*
    |--------------------------------------------------------------------------
    | الوصول السريع (Accessors) و Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * جلب النزيل الأساسي بناءً على نوع المشاركة في الجدول الوسيط
     */
    public function getPrimaryGuestAttribute()
    {
        return $this->occupants()->wherePivot('participant_type', 'primary')->first();
    }

    public function scopeArrivingToday($query) { return $query->whereDate('check_in', today()); }
    
    public function scopeDepartingToday($query) { return $query->whereDate('check_out', today()); }
    
    public function scopeCurrentlyIn($query) { 
        return $query->where('status', 'confirmed')
                     ->where('check_in', '<=', now())
                     ->where(function($q) {
                         $q->whereNull('check_out')->orWhere('check_out', '>=', now());
                     });
    }
}
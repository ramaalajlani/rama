<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; 
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Guest extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'guests';

    protected $fillable = [
        'first_name', 'father_name', 'last_name', 'mother_name',
        'national_id', 'id_type', 'nationality', 'phone', 'email',
        'address', 'car_plate', 'national_id_hash', 'full_security_hash',
        'audit_status', 'audited_at', 'audited_by', 'audit_notes',
        'is_flagged', 'status', 
    ];

    /**
     * 1. تعديل الحقول المخفية:
     * أضفت العلاقات التي تسبب الدوران (Circular References) هنا
     */
    protected $hidden = [
        'national_id_hash',
        'full_security_hash',
        'reservations',      // إخفاء لمنع الدوران
        'latestReservation', // إخفاء لمنع الدوران
        'personalDocuments'
    ];

    /**
     * 2. تحذير بخصوص $appends:
     * تركت full_name لأنه نصي، لكن أزلت current_stay من هنا 
     * لأن استدعاءه تلقائياً عند كل طلب هو ما يسبب تعليق السيرفر.
     */
    protected $appends = ['full_name'];

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
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->father_name} {$this->last_name}";
    }

    /**
     * تم إبقاء الوصول لـ current_stay كدالة يدوية وليس Append تلقائي
     */
    public function getCurrentStayAttribute()
    {
        return $this->latestReservation->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guest', 'guest_id', 'reservation_id')
                    ->withPivot(['participant_type', 'vehicle_plate_at_checkin', 'registered_by']) 
                    ->withTimestamps();
    }

    public function latestReservation(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guest', 'guest_id', 'reservation_id')
                    ->withPivot(['participant_type', 'vehicle_plate_at_checkin'])
                    ->latest('reservation_guest.created_at')
                    ->limit(1);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function personalDocuments(): HasMany
    {
        return $this->hasMany(GuestDocument::class, 'guest_id');
    }
}
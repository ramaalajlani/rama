<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles; 
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions; 

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;

    /**
     * تحديد الـ Guard الافتراضي للموديل
     * هذا يضمن توافق الصلاحيات مع Sanctum بشكل تلقائي
     */
    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
        'status', 
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * إعدادات سجل النشاطات (Activity Log)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'branch_id', 'status'])
            ->logOnlyDirty() 
            ->dontSubmitEmptyLogs() 
            ->useLogName('user_management'); 
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relations)
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | الدوال المساعدة (Helper Methods)
    |--------------------------------------------------------------------------
    */

    /**
     * التحقق مما إذا كان الحساب نشطاً
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * التحقق مما إذا كان المستخدم يتبع للمركز الرئيسي (HQ)
     */
    public function isHQ(): bool
    {
        return is_null($this->branch_id);
    }
    public function getFullNameAttribute(): string
    {
        return $this->name;
    }
}
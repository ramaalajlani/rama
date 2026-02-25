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

    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
        'status', 
    ];

    /**
     * 1. تحصين الحقول المخفية:
     * أضفت 'branch' و 'reservations' و 'roles' و 'permissions' للمخفيات.
     * هذا يمنع Laravel من محاولة تحويل كل صلاحيات وأدوار وحجوزات المستخدم إلى JSON 
     * تلقائياً عند جلب "الحجز"، مما يكسر حلقة الدوران اللانهائية تماماً.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'branch',
        'reservations',
        'roles',
        'permissions',
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isHQ(): bool
    {
        return is_null($this->branch_id);
    }

    public function getFullNameAttribute(): string
    {
        return $this->name;
    }
}
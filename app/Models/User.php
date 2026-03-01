<?php
// app/Models/User.php

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

    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'branch_id' => 'integer',
        'status' => 'string',
    ];

    /*
    |--------------------------------------------------------------------------
    | Activity Log (إدارة المستخدمين)
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user_management')
            ->logOnly([
                'name',
                'email',
                'branch_id',
                'status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {

                    'created' =>
                        "تم إنشاء مستخدم جديد: {$this->name}",

                    'updated' =>
                        $this->wasChanged('status')
                            ? ($this->status === 'active'
                                ? "تم تفعيل حساب المستخدم: {$this->name}"
                                : "تم تعطيل حساب المستخدم: {$this->name}")
                            : ($this->wasChanged('branch_id')
                                ? "تم تغيير فرع المستخدم: {$this->name}"
                                : "تم تعديل بيانات المستخدم: {$this->name}"),

                    'deleted' =>
                        "تم حذف منطقي لحساب المستخدم: {$this->name}",

                    'restored' =>
                        "تم استرجاع حساب المستخدم: {$this->name}",

                    default =>
                        "تم تنفيذ إجراء على حساب المستخدم: {$this->name}",
                };
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(GuestDocument::class, 'uploaded_by');
    }

    public function readNotifications(): HasMany
    {
        return $this->hasMany(SecurityNotification::class, 'read_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
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
        return (string) $this->name;
    }
}
<?php
// app/Models/Branch.php

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

    protected $table = 'branches';

    protected $fillable = [
        'name',
        'address',
        'phone',
        'city',
        'manager_name',
        'status',
    ];

    protected $hidden = ['deleted_at'];

    protected $casts = [
        'id'     => 'integer',
        'status' => 'string',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('branch_management')
            ->logOnly(['name', 'status', 'address', 'phone', 'city', 'manager_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {
                return match ($eventName) {
                    'created'  => "تم إنشاء الفرع: {$this->name}",
                    'updated'  => "تم تعديل بيانات الفرع: {$this->name}",
                    'deleted'  => "تم تعطيل الفرع (حذف منطقي): {$this->name}",
                    'restored' => "تم استرجاع الفرع: {$this->name}",
                    default    => "تم تنفيذ إجراء على الفرع: {$this->name}",
                };
            });
    }

    // Relationships
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'branch_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'branch_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
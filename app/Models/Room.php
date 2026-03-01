<?php
// app/Models/Room.php

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
        'branch_id',
        'room_number',
        'floor_number',
        'type',
        'status',
        'description',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'floor_number' => 'integer',
        'status' => 'string',
        'type' => 'string',
    ];

    /*
    |--------------------------------------------------------------------------
    | Activity Log (Spatie)
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('facility_management')
            ->logOnly([
                'room_number',
                'floor_number',
                'status',
                'branch_id',
                'type',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {

                    'created' =>
                        "تم إنشاء غرفة رقم {$this->room_number} (طابق {$this->floor_number})",

                    'updated' =>
                        $this->wasChanged('status')
                            ? "تم تغيير حالة الغرفة {$this->room_number} إلى ({$this->status})"
                            : "تم تعديل بيانات الغرفة {$this->room_number}",

                    'deleted' =>
                        "تم تعطيل الغرفة {$this->room_number} (حذف منطقي)",

                    'restored' =>
                        "تم استرجاع الغرفة {$this->room_number}",

                    default =>
                        "تم تنفيذ إجراء على الغرفة {$this->room_number}",
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
        return $this->hasMany(Reservation::class, 'room_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (Indexed)
    |--------------------------------------------------------------------------
    */

    public function scopeAvailable($q)
    {
        return $q->where('status', 'available');
    }

    public function scopeInMaintenance($q)
    {
        return $q->where('status', 'maintenance');
    }

    public function scopeOccupied($q)
    {
        return $q->where('status', 'occupied');
    }

    public function scopeForBranch($q, int $branchId)
    {
        return $q->where('branch_id', $branchId);
    }
}
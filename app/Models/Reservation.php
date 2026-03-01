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

    protected $table = 'guest_reservations';

    protected $fillable = [
        'room_id', 'branch_id', 'user_id',
        'check_in', 'check_out', 'actual_check_out',
        'vehicle_plate',
        'is_locked', 'locked_by',
        'audit_status', 'audited_at', 'audited_by',
        'status', 'security_notes', 'audit_notes',
    ];

    protected $casts = [
        'id'              => 'integer',
        'room_id'         => 'integer',
        'branch_id'       => 'integer',
        'user_id'         => 'integer',
        'locked_by'       => 'integer',
        'audited_by'      => 'integer',
        'is_locked'       => 'boolean',
        'check_in'        => 'datetime',
        'check_out'       => 'datetime',
        'actual_check_out'=> 'datetime',
        'audited_at'      => 'datetime',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reservation_security')
            ->logOnly([
                'status',
                'audit_status',
                'is_locked',
                'room_id',
                'check_in',
                'check_out',
                'vehicle_plate',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {
                    'created'  => "تم إنشاء إقامة جديدة رقم #{$this->id}",
                    'updated'  => $this->is_locked
                        ? "تم تعديل إقامة مقفلة رقم #{$this->id} (إجراء استثنائي)"
                        : "تم تعديل بيانات الإقامة رقم #{$this->id}",
                    'deleted'  => "تم تعطيل سجل الإقامة رقم #{$this->id} (حذف منطقي)",
                    'restored' => "تم استرجاع سجل الإقامة رقم #{$this->id}",
                    default    => "تم تنفيذ إجراء على سجل الإقامة رقم #{$this->id}",
                };
            });
    }

    protected static function booted(): void
    {
        static::addGlobalScope('branch_access', function (Builder $builder) {
            if (!Auth::check() || app()->runningInConsole()) return;

            $user = Auth::user();

            if (!$user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor'])) {
                $builder->where($builder->getQuery()->from . '.branch_id', (int)$user->branch_id);
            }
        });

        static::creating(function (self $reservation) {
            if (!Auth::check()) return;

            $user = Auth::user();

            $reservation->branch_id    = $reservation->branch_id ?? $user->branch_id;
            $reservation->user_id      = $reservation->user_id ?? $user->id;
            $reservation->audit_status = $reservation->audit_status ?? 'new';
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function occupants(): BelongsToMany
    {
        return $this->belongsToMany(
                Guest::class,
                'reservation_guest',
                'reservation_id',
                'guest_id'
            )
            ->using(ReservationGuest::class)
            ->withPivot([
                'participant_type',
                'vehicle_plate_at_checkin',
                'registered_by',
                'companion_of_guest_id',
                'relationship',
            ])
            ->withTimestamps();
    }

    public function guests(): BelongsToMany
    {
        return $this->occupants();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GuestDocument::class, 'reservation_id');
    }

    public function reservationGuests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class, 'reservation_id', 'id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    public function getPrimaryGuestAttribute()
    {
        if (!$this->relationLoaded('occupants')) {
            return null;
        }

        return $this->occupants->firstWhere('pivot.participant_type', 'primary');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopePendingAudit($q)
    {
        return $q->where('audit_status', 'new');
    }

    public function scopeCurrentlyIn($q)
    {
        return $q->where('status', 'confirmed')->whereNull('actual_check_out');
    }

    public function scopeForBranch($q, int $branchId)
    {
        return $q->where('branch_id', $branchId);
    }
}
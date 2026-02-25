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
        'room_id', 'branch_id', 'user_id', 'check_in', 'check_out', 
        'actual_check_out', 'is_locked', 'locked_by', 'audit_status',
        'audited_at', 'audited_by', 'status', 'security_notes',
        'audit_notes', 'vehicle_plate' 
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'actual_check_out' => 'datetime',
        'audited_at' => 'datetime',
    ];

    /**
     * 1. إزالة primary_guest من الـ appends:
     * هذا هو التعديل الأهم. لا تجعل الحجز يبحث عن النزلاء تلقائياً 
     * لأنك تستدعي النزلاء بالفعل عبر with('occupants') في الـ Controller.
     */
    protected $appends = []; 

    /**
     * 2. الحقول المخفية لمنع الدوران:
     */
    protected $hidden = [
        'branch',
        'room',
        'creator',
        'auditor'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 'audit_status', 'is_locked', 'room_id', 
                'check_in', 'check_out', 'vehicle_plate', 'audit_notes'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('security_monitor') 
            ->setDescriptionForEvent(fn(string $eventName) => "إجراء أمني: تم {$eventName} سجل الإقامة رقم #{$this->id}");
    }

    protected static function booted()
    {
        static::addGlobalScope('branch_access', function (Builder $builder) {
            if (Auth::check() && !app()->runningInConsole()) {
                $user = Auth::user();
                if (!$user->hasAnyRole(['hq_admin', 'hq_security', 'hq_auditor', 'hq_supervisor'])) {
                    $builder->where($builder->getQuery()->from . '.branch_id', $user->branch_id);
                }
            }
        });

        static::creating(function ($reservation) {
            if (Auth::check()) {
                $user = Auth::user();
                $reservation->branch_id = $reservation->branch_id ?? $user->branch_id;
                $reservation->user_id = $reservation->user_id ?? $user->id;
                $reservation->audit_status = $reservation->audit_status ?? 'new';
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات (Relationships)
    |--------------------------------------------------------------------------
    */

    public function occupants(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guest', 'reservation_id', 'guest_id')
                    ->withPivot(['participant_type', 'vehicle_plate_at_checkin', 'registered_by'])
                    ->withTimestamps();
    }

    public function guests(): BelongsToMany { return $this->occupants(); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function locker(): BelongsTo { return $this->belongsTo(User::class, 'locked_by'); }
    public function auditor(): BelongsTo { return $this->belongsTo(User::class, 'audited_by'); }
    public function documents(): HasMany { return $this->hasMany(GuestDocument::class, 'reservation_id'); }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * تعديل Accessor: نجعله يتحقق إذا كانت العلاقة محملة مسبقاً 
     * لمنع استعلامات SQL إضافية أثناء التحويل لـ JSON.
     */
    public function getPrimaryGuestAttribute()
    {
        if (!$this->relationLoaded('occupants')) {
            return null;
        }
        return $this->occupants->where('pivot.participant_type', 'primary')->first();
    }

    public function scopePendingAudit($query) { return $query->where('audit_status', 'new'); }
    public function scopeAudited($query) { return $query->where('audit_status', 'audited'); }

    public function scopeCurrentlyIn($query) { 
        return $query->where('status', 'confirmed')
                     ->whereNull('actual_check_out');
    }
}
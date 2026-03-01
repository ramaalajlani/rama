<?php
// app/Models/SecurityNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SecurityNotification extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_notifications';

    protected $fillable = [
        'blacklist_id',
        'guest_id',
        'reservation_id',
        'branch_name',
        'receptionist_name',
        'car_plate_captured',
        'risk_level',
        'alert_message',
        'instructions',
        'read_at',
        'read_by',
    ];

    protected $casts = [
        'id'             => 'integer',
        'blacklist_id'   => 'integer',
        'guest_id'       => 'integer',
        'reservation_id' => 'integer',
        'read_by'        => 'integer',
        'read_at'        => 'datetime',
        'risk_level'     => 'string',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Activity Log (أمني مركزي)
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('security_alerts')
            ->logOnly([
                'risk_level',
                'read_at',
                'read_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {

                    'created' =>
                        "تم إنشاء تنبيه أمني مرتبط بالحجز رقم #{$this->reservation_id}",

                    'updated' =>
                        $this->wasChanged('read_at')
                            ? "تم الاطلاع على التنبيه الأمني المرتبط بالحجز رقم #{$this->reservation_id}"
                            : "تم تعديل بيانات تنبيه أمني",

                    'deleted' =>
                        "تم حذف منطقي لتنبيه أمني مرتبط بالحجز رقم #{$this->reservation_id}",

                    'restored' =>
                        "تم استرجاع تنبيه أمني مرتبط بالحجز رقم #{$this->reservation_id}",

                    default =>
                        "تم تنفيذ إجراء على تنبيه أمني",
                };
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function blacklist(): BelongsTo
    {
        return $this->belongsTo(SecurityBlacklist::class, 'blacklist_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeUnread($q)
    {
        return $q->whereNull('read_at');
    }

    public function scopeRead($q)
    {
        return $q->whereNotNull('read_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
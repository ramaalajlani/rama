<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ReservationGuest extends Pivot
{
    use LogsActivity;

    protected $table = 'reservation_guest';

    public $timestamps = true;

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'participant_type',
        'vehicle_plate_at_checkin',
        'registered_by',
        'companion_of_guest_id',
        'relationship',
    ];

    protected $casts = [
        'reservation_id'        => 'integer',
        'guest_id'              => 'integer',
        'registered_by'         => 'integer',
        'companion_of_guest_id' => 'integer',
        'participant_type'      => 'string',
        'relationship'          => 'string',
    ];

    protected $hidden = [
        'reservation',
        'guest',
        'companionOf',
        'registeredBy',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('reservation_participants')
            ->logOnly([
                'reservation_id',
                'guest_id',
                'participant_type',
                'relationship',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {
                    'created' =>
                        "تم ربط نزيل رقم {$this->guest_id} بالإقامة رقم #{$this->reservation_id}",
                    'updated' =>
                        "تم تعديل بيانات مشاركة نزيل رقم {$this->guest_id} في الإقامة رقم #{$this->reservation_id}",
                    'deleted' =>
                        "تم إزالة نزيل رقم {$this->guest_id} من الإقامة رقم #{$this->reservation_id}",
                    default =>
                        "تم تنفيذ إجراء على مشاركة نزيل في إقامة رقم #{$this->reservation_id}",
                };
            });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function companionOf(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'companion_of_guest_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
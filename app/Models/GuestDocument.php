<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestDocument extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'guest_documents';

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'document_type',
        'file_path',
        'file_name',
        'file_hash',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $hidden = [
        'file_path',
        'file_hash',
    ];

    protected $casts = [
        'id'             => 'integer',
        'reservation_id' => 'integer',
        'guest_id'       => 'integer',
        'uploaded_by'    => 'integer',
        'file_size'      => 'integer',
        'document_type'  => 'string',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('guest_documents_security')
            ->logOnly([
                'document_type',
                'file_name',
                'guest_id',
                'reservation_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                $guestName = $this->relationLoaded('guest') && $this->guest
                    ? $this->guest->full_name
                    : "نزيل رقم {$this->guest_id}";

                return match ($eventName) {
                    'created'  => "تم رفع وثيقة للنزيل: {$guestName} ضمن الحجز رقم #{$this->reservation_id}",
                    'updated'  => "تم تعديل بيانات وثيقة للنزيل: {$guestName} ضمن الحجز رقم #{$this->reservation_id}",
                    'deleted'  => "تم حذف وثيقة للنزيل: {$guestName} ضمن الحجز رقم #{$this->reservation_id}",
                    default    => "تم إجراء عملية على وثيقة تخص النزيل: {$guestName}",
                };
            });
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isIntegrityValid(): bool
    {
        if (!Storage::disk('private')->exists($this->file_path)) {
            return false;
        }

        $currentHash = hash_file('sha256', Storage::disk('private')->path($this->file_path));

        return hash_equals((string)$this->file_hash, (string)$currentHash);
    }
}
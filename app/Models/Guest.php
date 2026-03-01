<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Guest extends Model
{
    use SoftDeletes, HasFactory, LogsActivity;

    protected $table = 'guests';

    protected $fillable = [
        'first_name', 'father_name', 'last_name', 'mother_name',
        'national_id', 'id_type', 'nationality', 'phone', 'email',
        'car_plate',
        // hashes تتولد تلقائياً
        'audit_status', 'audited_at', 'audited_by', 'audit_notes',
        'is_flagged', 'status',
    ];

    protected $hidden = [
        'national_id_hash',
        'full_security_hash',
        'deleted_at',
    ];

    protected $casts = [
        'id'           => 'integer',
        'audited_by'   => 'integer',
        'audited_at'   => 'datetime',
        'is_flagged'   => 'boolean',
        'status'       => 'string',
        'audit_status' => 'string',
    ];

    /**
     * توليد الهاشات الأمنية تلقائياً
     * IMPORTANT: يعمل فقط مع Eloquent (create/update/updateOrCreate)
     */
    protected static function booted(): void
    {
        static::saving(function (self $g) {

            $nid = trim((string)($g->national_id ?? ''));
            $appKey = (string) config('app.key', '');

            // إذا الهوية فاضية لا تولد (لكن غالباً عندك validation يمنع)
            if ($nid !== '') {
                $g->national_id_hash = hash('sha256', $nid . $appKey);
            }

            // full_security_hash: بصمة مركبة داخلية (ليست شرطاً نفس بصمة blacklist)
            $full = trim(
                (string)($g->first_name ?? '') . '|' .
                (string)($g->father_name ?? '') . '|' .
                (string)($g->last_name ?? '') . '|' .
                (string)($g->mother_name ?? '') . '|' .
                (string)($g->nationality ?? '') . '|' .
                $nid
            );

            if ($nid !== '' && $full !== '') {
                $g->full_security_hash = hash('sha256', mb_strtolower($full, 'UTF-8') . $appKey);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Activity Log (Spatie)
    |--------------------------------------------------------------------------
    */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('guest_security')
            ->logOnly([
                'first_name',
                'father_name',
                'last_name',
                'national_id',
                'is_flagged',
                'status',
                'audit_status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                $fullName = $this->full_name;

                return match ($eventName) {
                    'created'  => "تم إنشاء سجل النزيل: {$fullName}",
                    'updated'  => "تم تعديل بيانات النزيل: {$fullName}",
                    'deleted'  => "تم تعطيل سجل النزيل (حذف منطقي): {$fullName}",
                    'restored' => "تم استرجاع سجل النزيل: {$fullName}",
                    default    => "تم تنفيذ إجراء على سجل النزيل: {$fullName}",
                };
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessor
    |--------------------------------------------------------------------------
    */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->father_name} {$this->last_name} {$this->mother_name}");
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }

    public function personalDocuments(): HasMany
    {
        return $this->hasMany(GuestDocument::class, 'guest_id');
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(
                Reservation::class,
                'reservation_guest',
                'guest_id',
                'reservation_id'
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

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopeFlagged($q)
    {
        return $q->where('is_flagged', true);
    }

    public function scopePendingAudit($q)
    {
        return $q->where('audit_status', 'new');
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }
}
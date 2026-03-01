<?php
// app/Models/SecurityBlacklist.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SecurityBlacklist extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'security_blacklists';

    protected $fillable = [
        'identity_hash',
        'full_name_hash',
        'father_name_hash',
        'mother_name_hash',
        'triple_check_hash',
        'full_hash',
        'risk_level',
        'reason',
        'instructions',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_by' => 'integer',
        'is_active' => 'boolean',
        'risk_level' => 'string',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Activity Log (أمني رسمي)
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('blacklist_security')
            ->logOnly([
                'risk_level',
                'reason',
                'instructions',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {

                return match ($eventName) {

                    'created' =>
                        "تمت إضافة سجل جديد إلى القائمة السوداء (مستوى الخطورة: {$this->risk_level})",

                    'updated' =>
                        $this->wasChanged('is_active')
                            ? ($this->is_active
                                ? "تم تفعيل سجل في القائمة السوداء"
                                : "تم تعطيل سجل في القائمة السوداء")
                            : "تم تعديل بيانات سجل في القائمة السوداء",

                    'deleted' =>
                        "تم حذف منطقي لسجل من القائمة السوداء",

                    'restored' =>
                        "تم استرجاع سجل من القائمة السوداء",

                    default =>
                        "تم تنفيذ إجراء أمني على القائمة السوداء",
                };
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SecurityNotification::class, 'blacklist_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

public function scopeHighRisk($q)
{
    return $q->whereIn('risk_level', ['CRITICAL','DANGER','BANNED']);
}
}
<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait Observable
{
    use LogsActivity;

    /**
     * إعدادات سجل النشاطات (Activity Log)
     * تم تصحيح الدوال لتتوافق مع الإصدار الأحدث للمكتبة
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // تسجيل جميع الحقول القابلة للتعبئة
            ->logFillable()
            
            // تسجيل الحقول التي تغيرت فقط (سيقوم تلقائياً بحفظ القيم القديمة والجديدة)
            ->logOnlyDirty()
            
            // عدم تسجيل أي نشاط إذا لم يحدث تغيير حقيقي في البيانات
            ->dontSubmitEmptyLogs()
            
            // تحديد اسم السجل بناءً على اسم الموديل (User, Room, etc.)
            ->useLogName(class_basename($this))
            
            // تخصيص الوصف ليظهر اسم الموظف والعملية بالعربية
            ->setDescriptionForEvent(function(string $eventName) {
                // محاولة جلب اسم المستخدم، وإذا لم يوجد (مثل التست أو السيدر) يظهر "النظام"
                $user = auth()->user() ? auth()->user()->name : 'النظام';
                
                // ترجمة الأحداث للغة العربية لسهولة التدقيق
                $eventArabic = match ($eventName) {
                    'created' => 'إضافة',
                    'updated' => 'تعديل',
                    'deleted' => 'حذف',
                    'restored' => 'استعادة',
                    default   => $eventName,
                };

                return "قام الموظف ({$user}) بإجراء عملية {$eventArabic} على " . class_basename($this);
            });
    }
}
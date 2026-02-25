<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل التهيئة لجدول الحجوزات الأمنية المطور
     * يطابق وثيقة المتطلبات الفنية 2026
     */
    public function up(): void
    {
        Schema::create('guest_reservations', function (Blueprint $table) {
            $table->id();
            
            // 1. الربط الإداري وعزل الفروع (المنع الافتراضي)
            $table->foreignId('room_id')->constrained()->onDelete('restrict');
            $table->foreignId('branch_id')->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); // الموظف المنفذ
            
            // 2. سجلات الحركة الزمنية
            $table->dateTime('check_in')->index(); 
            $table->dateTime('check_out')->nullable()->index(); 
            $table->dateTime('actual_check_out')->nullable(); // للتدقيق الفعلي
        
            // 3. التتبع اللوجستي (رقم السيارة)
            $table->string('vehicle_plate')->nullable()->index(); 

            // 4. نظام القفل المركزي (HQ Control) - البند 4 في الوثيقة
            $table->boolean('is_locked')->default(false)->index(); 
            $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('set null');

            // 5. حالات التدقيق الأمني (Audit System) - البند 3 في الوثيقة
            // new: لم يدقق بعد | audited: تم التدقيق وقفل السجل | flagged: مشتبه به/مرفوض
            $table->enum('audit_status', ['new', 'audited', 'flagged'])->default('new')->index();
            $table->dateTime('audited_at')->nullable();
            $table->foreignId('audited_by')->nullable()->constrained('users')->onDelete('set null');
            
            // 6. سجل الملاحظات وأسباب التعديل الاستثنائي
            $table->text('security_notes')->nullable(); // ملاحظات الاستقبال
            $table->text('audit_notes')->nullable();    // ملاحظات المدقق + سبب التعديل بعد القفل
            
            // 7. الحالة الإجرائية للحجز
            $table->enum('status', ['pending', 'confirmed', 'checked_out', 'cancelled'])->default('confirmed')->index();
            
            // 8. سياسة الأمان والتدقيق الشامل
            $table->softDeletes(); // سياسة "لا حذف نهائي" - البند 8
            $table->timestamps();
        });
    }

    /**
     * تراجع عن التهيئة
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_reservations');
    }
};
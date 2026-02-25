<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل التهيئة لجدول الحجوزات الأمنية
     */
    public function up(): void
    {
        Schema::create('guest_reservations', function (Blueprint $table) {
            $table->id();
            
            // 1. الربط الإداري (بدون حذف لمنع ضياع السجل الأمني)
            $table->foreignId('room_id')->constrained()->onDelete('restrict');
            $table->foreignId('branch_id')->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); 
            
            // 2. سجلات الحركة (Check-in/Out)
            $table->dateTime('check_in')->index(); 
            $table->dateTime('check_out')->nullable()->index(); 
        
            // 3. التتبع اللوجستي (رقم السيارة المستخدمة في هذا الحجز)
            $table->string('vehicle_plate')->nullable()->index(); 

            // 4. نظام التحكم المركزي (HQ Control)
            // الحجز المقفل لا يمكن تعديله أو عمل خروج له إلا بفك القفل من HQ
            $table->boolean('is_locked')->default(false)->index(); 
            $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('set null');

            // 5. الملاحظات والرقابة
            // هنا يكتب موظف الاستقبال أو المدقق أي ملاحظات سلوكية عن النزيل
            $table->text('security_notes')->nullable(); 
            
            // 6. الحالة الإجرائية للحجز
            // 'confirmed': النزيل داخل الفندق، 'checked_out': غادر، 'cancelled': ملغى
            $table->enum('status', ['pending', 'confirmed', 'checked_out', 'cancelled'])->default('confirmed')->index();
            
            $table->softDeletes(); // سياسة "لا حذف نهائي" للبيانات الأمنية
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
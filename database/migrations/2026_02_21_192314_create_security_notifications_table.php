<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_notifications', function (Blueprint $table) {
            $table->id();
            
            // 1. روابط المصدر (للوصول السريع لكل المعلومات)
            $table->foreignId('blacklist_id')->constrained('security_blacklists');
            $table->foreignId('guest_id')->constrained('guests'); // ليعرف النظام من هو الشخص الموقوف
            $table->foreignId('reservation_id')->nullable()->constrained('guest_reservations'); // ليعرف في أي حجز حاول الدخول

            // 2. تفاصيل الموقع والموظف (للمحاسبة والتحقيق)
            $table->string('branch_name'); 
            $table->string('receptionist_name'); // اسم موظف الاستقبال الذي باشر العملية
            
            // 3. البيانات اللوجستية وقت الحادثة (Snapshots)
            // نخزنها هنا كـ "نص" لتبقى مرجعاً حتى لو تغيرت بيانات النزيل لاحقاً
            $table->string('car_plate_captured')->nullable(); // رقم السيارة التي كان يقودها وقت محاولة الدخول
            $table->string('risk_level'); // مستوى الخطورة وقت التنبيه
            
            // 4. المحتوى والتعليمات
            $table->text('alert_message'); // رسالة توضيحية (مثلاً: محاولة دخول شخص محظور دولياً)
            $table->text('instructions')->nullable(); // التعليمات التي ظهرت للموظف
            
            // 5. حالة الإشعار في الـ HQ
            $table->timestamp('read_at')->nullable(); 
            $table->foreignId('read_by')->nullable()->constrained('users'); // المدقق الذي استلم التنبيه وعالجه
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_notifications');
    }
};
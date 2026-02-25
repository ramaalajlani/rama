<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            
            // 1. البيانات التعريفية المفصلة (Normalized Identity)
            $table->string('first_name');    // الاسم الأول
            $table->string('father_name');   // اسم الأب (منفصل)
     
            $table->string('last_name');     // اللقب / العائلة
            $table->string('mother_name');   // اسم الأم (منفصل)
            
            $table->string('national_id')->unique();
            $table->string('id_type'); // national_id, passport, residency
            $table->string('nationality');
            $table->string('car_plate')->nullable(); // رقم السيارة

            // 2. الطبقة الأمنية المشفرة (Hashing Layer)
            // نستخدم الهاش للمطابقة السريعة مع البلاك ليست دون كشف البيانات
            $table->string('national_id_hash')->index(); 
            // هاش مدمج (الاسم + الأب + الأم) للمطابقة الثلاثية الصارمة
            $table->string('full_security_hash')->index(); 

            // 3. نظام التدقيق والرصد (Audit System - HQ)
            // 'new': جديد، 'audited': تم التدقيق من HQ، 'flagged': عليه ملاحظة أمنية
            $table->enum('audit_status', ['new', 'audited', 'flagged'])->default('new')->index();
            $table->timestamp('audited_at')->nullable();
            $table->foreignId('audited_by')->nullable()->constrained('users');
            $table->text('audit_notes')->nullable(); // ملاحظات المدقق الأمني

            // 4. الرصد الصامت والحالة العامة
            $table->boolean('is_flagged')->default(false); 
            $table->string('status')->default('active'); // active, blacklisted, suspended
            
            // 5. التواصل والأرشفة
            $table->string('phone');
            $table->string('email')->nullable();
            $table->softDeletes(); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
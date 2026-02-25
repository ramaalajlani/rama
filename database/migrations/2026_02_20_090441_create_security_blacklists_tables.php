<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل التهيئة لجدول القائمة السوداء - النظام الأمني المشفر 2026
     */
    public function up(): void
    {
        Schema::create('security_blacklists', function (Blueprint $table) {
            $table->id();

            // 1. بصمات الهوية المشفرة (Identification Hashes)
            $table->string('identity_hash')->unique()->index(); // هاش الرقم الوطني

            // 2. بصمات الأسماء المفصلة (Name Hashes)
            $table->string('full_name_hash')->index();    // هاش الاسم الكامل واللقب
            $table->string('father_name_hash')->nullable()->index();  // هاش اسم الأب منفصلاً
            $table->string('mother_name_hash')->nullable()->index();  // هاش اسم الأم منفصلاً
            
            // بصمة أمنية مدمجة (الاسم + الأب + الأم) للمطابقة الثلاثية
            $table->string('triple_check_hash')->index(); 

            // 3. البصمة الشاملة (Full Hash) - الحقل الذي كان مفقوداً
            // (الاسم الأول + الأب + الجد/العائلة + الأم)
            $table->string('full_hash')->index(); 

            // 4. تصنيف المخاطر والتعليمات
            // risk_level: (LOW, MEDIUM, HIGH, CRITICAL, WATCHLIST)
            $table->string('risk_level')->default('WATCHLIST'); 
            $table->text('reason')->nullable();       // سبب الإدراج (يظهر للإدارة فقط)
            $table->text('instructions')->nullable(); // تعليمات موظف الاستقبال عند المطابقة

            // 5. الرقابة والمسؤولية
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null'); // الموظف المسؤول في HQ عن الإضافة
            
            $table->boolean('is_active')->default(true); // حالة القيد (فعال/معطل)
            $table->timestamps();
        });
    }

    /**
     * تراجع عن التهيئة
     */
    public function down(): void
    {
        Schema::dropIfExists('security_blacklists');
    }
};
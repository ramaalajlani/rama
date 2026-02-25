<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل التهيئة لجدول الوثائق الأمنية - الإصدار الرقابي 2026
     */
    public function up(): void
    {
        Schema::create('guest_documents', function (Blueprint $table) {
            $table->id();

            // 1. الروابط الأساسية (التدقيق المتقاطع)
            // الحجز المرتبط بالوثيقة
            $table->foreignId('reservation_id')
                  ->constrained('guest_reservations')
                  ->onDelete('cascade');

            // النزيل صاحب الوثيقة
            $table->foreignId('guest_id')
                  ->constrained('guests')
                  ->onDelete('cascade');

            // 2. البيانات التقنية للملف (Security & Storage)
            $table->string('file_path');    // مسار التخزين (يفضل أن يكون Private Storage)
            $table->string('file_name');    // اسم الملف عند الرفع
            
            // بصمة الملف (Hash): لضمان عدم استبدال صورة الهوية بعد تدقيقها من الـ HQ
            $table->string('file_hash')->index(); 
            
            $table->string('mime_type');    // نوع الملف (image/jpeg, application/pdf)
            $table->integer('file_size');   // حجم الملف بالكيلوبايت

            // 3. التصنيف الرقابي
            // أنواع الوثائق: identity (هوية), passport (جواز), personal_photo (صورة شخصية)
            // ملاحظة: رقم السيارة لا يرفع هنا كصورة بل يسجل نصاً في جدول الحجوزات
            $table->string('document_type')->default('identity')->index();

            // 4. سجل المسؤولية (Audit Trail)
            // معرف الموظف الذي قام برفع الوثيقة في الفرع
            $table->foreignId('uploaded_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * تراجع عن التهيئة
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_documents');
    }
};
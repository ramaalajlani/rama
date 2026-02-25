<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guest', function (Blueprint $table) {
            $table->id();

            // 1. الربط الأساسي
            $table->foreignId('reservation_id')
                  ->constrained('guest_reservations')
                  ->onDelete('cascade');

            $table->foreignId('guest_id')
                  ->constrained('guests')
                  ->onDelete('restrict');

            // 2. نوع التواجد (أساسي أو مرافق)
            $table->enum('participant_type', ['primary', 'companion'])->default('companion');

            // 3. التتبع اللوجستي (رقم السيارة نصاً فقط)
            // سجلنا السيارة هنا لربط كل نزيل بمركبته الخاصة أثناء هذا الحجز
            $table->string('vehicle_plate_at_checkin')->nullable()->index(); 

            // 4. الرقابة الأمنية (بصمة مدخل البيانات)
            // لمعرفة من الموظف الذي سجل هذا النزيل تحديداً في هذا الحجز
            $table->foreignId('registered_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guest');
    }
};
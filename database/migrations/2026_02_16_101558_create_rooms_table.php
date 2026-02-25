<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            // الربط بالفرع
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            
            $table->string('room_number'); // رقم الغرفة (مثلاً 101)
            $table->integer('floor_number'); // رقم الطابق (مثلاً 1)
            $table->string('type'); // نوع الغرفة (فردي، زوجي، جناح)

            // الحالات التشغيلية
            $table->enum('status', ['available', 'occupied', 'maintenance'])->default('available');
            
            $table->text('description')->nullable(); 
            $table->softDeletes(); 
            $table->timestamps();

  
            $table->unique(['branch_id', 'floor_number', 'room_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
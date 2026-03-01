<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // الهوية الأساسية للفرع
            $table->string('name')->unique();
            $table->string('city', 50); // مهم للفلترة الأمنية
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();

            // مدير الفرع (اختياري)
            $table->string('manager_name', 100)->nullable();

            // الحالة التشغيلية
            $table->enum('status', ['active', 'inactive'])->default('active');

            // أمان وأداء
            $table->softDeletes();
            $table->timestamps();

            /*
            |------------------------------------------------------
            | Indexes (تحسين الأداء)
            |------------------------------------------------------
            */

            $table->index('city');
            $table->index('status');
            $table->index(['status', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
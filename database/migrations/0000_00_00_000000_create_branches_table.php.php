<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
   $table->id();
        $table->string('name')->unique();
        $table->string('address')->nullable();
        $table->string('phone')->nullable(); // أضف هذا السطر
        $table->string('status')->default('active'); // أضف هذا السطر
        $table->softDeletes();         
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

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

            $table->foreignId('branch_id')
                  ->constrained('branches')
                  ->cascadeOnDelete();

            $table->string('room_number');
            $table->integer('floor_number');
            $table->string('type');

            $table->enum('status', ['available', 'occupied', 'maintenance'])->default('available');

            $table->text('description')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['branch_id', 'floor_number', 'room_number']);

            // Indexing (performance)
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'type']);
            $table->index(['branch_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
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

            $table->foreignId('reservation_id')
                ->constrained('guest_reservations')
                ->cascadeOnDelete();

            $table->foreignId('guest_id')
                ->constrained('guests')
                ->restrictOnDelete();

            $table->foreignId('companion_of_guest_id')
                ->nullable()
                ->constrained('guests')
                ->restrictOnDelete();

            $table->enum('participant_type', ['primary', 'companion'])->default('companion');

            $table->string('relationship', 30)->nullable();

            $table->string('vehicle_plate_at_checkin')->nullable()->index();

            $table->foreignId('registered_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Integrity
            $table->unique(['reservation_id', 'guest_id'], 'uq_reservation_guest');

            // -------------------------
            // Performance indexes
            // -------------------------
            $table->index(['guest_id', 'reservation_id'], 'idx_guest_reservation');
            $table->index(['guest_id', 'id'], 'idx_guest_last_row');

            // ✅ أهم فهرس لسرعة جلب primary
            $table->index(['reservation_id', 'participant_type'], 'idx_res_primary');

            $table->index(['reservation_id', 'companion_of_guest_id'], 'idx_res_companion_of');
            $table->index(['companion_of_guest_id', 'relationship'], 'idx_companion_relationship');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guest');
    }
};
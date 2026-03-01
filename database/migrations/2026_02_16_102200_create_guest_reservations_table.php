<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_reservations', function (Blueprint $table) {
            $table->id();

            // Core relations
            $table->foreignId('room_id')
                ->constrained('rooms')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Employee who created/handled reservation
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Time tracking
            $table->dateTime('check_in')->index();
            $table->dateTime('check_out')->nullable()->index();
            $table->dateTime('actual_check_out')->nullable()->index(); // ✅ مهم لأنه كثير whereBetween عليها

            // Logistics
            $table->string('vehicle_plate')->nullable()->index();

            // HQ Lock
            $table->boolean('is_locked')->default(false)->index();

            $table->foreignId('locked_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Audit system
            $table->enum('audit_status', ['new', 'audited', 'flagged'])->default('new')->index();
            $table->dateTime('audited_at')->nullable();

            $table->foreignId('audited_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('security_notes')->nullable();
            $table->text('audit_notes')->nullable();

            // Reservation status
            $table->enum('status', ['pending', 'confirmed', 'checked_out', 'cancelled'])
                ->default('confirmed')
                ->index();

            $table->softDeletes();
            $table->timestamps();

            // -------------------------
            // Indexing (performance)
            // -------------------------
            $table->index(['branch_id', 'status', 'check_in'], 'idx_branch_status_checkin');
            $table->index(['room_id', 'status', 'check_in'], 'idx_room_status_checkin');
            $table->index(['room_id', 'check_out'], 'idx_room_checkout');
            $table->index(['audit_status', 'is_locked', 'check_in'], 'idx_audit_lock_checkin');
            $table->index(['branch_id', 'audit_status', 'check_in'], 'idx_branch_audit_checkin');
            $table->index(['branch_id', 'deleted_at'], 'idx_branch_deleted');

            // current stays + HQ lists
            $table->index(['branch_id', 'actual_check_out'], 'idx_branch_actual_checkout');
            $table->index(['branch_id', 'is_locked'], 'idx_branch_locked');

            // ✅ NEW: أسرع لاستعلام due-checkouts-today
            // branch filter + whereBetween(check_out) + whereNull(actual_check_out)
            $table->index(['branch_id', 'check_out', 'actual_check_out'], 'idx_due_checkout_today');

            // ✅ NEW: أسرع لاستعلام today-checkouts (actual_check_out today)
            $table->index(['branch_id', 'actual_check_out'], 'idx_today_actual_checkout');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_reservations');
    }
};
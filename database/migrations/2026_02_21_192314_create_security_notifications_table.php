<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('blacklist_id')
                ->constrained('security_blacklists')
                ->restrictOnDelete();

            $table->foreignId('guest_id')
                ->constrained('guests')
                ->restrictOnDelete();

            $table->foreignId('reservation_id')
                ->nullable()
                ->constrained('guest_reservations')
                ->nullOnDelete();

            // Snapshots (reduce joins)
            $table->string('branch_name');
            $table->string('receptionist_name');

            $table->string('car_plate_captured')->nullable();
            $table->string('risk_level');

            $table->text('alert_message');
            $table->text('instructions')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->foreignId('read_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Indexing (Ultra)
            $table->index(['read_at', 'id']);         // ✅ unread سريع + latest('id')
            $table->index(['created_at']);
            $table->index(['blacklist_id', 'id']);
            $table->index(['guest_id', 'id']);
            $table->index(['reservation_id', 'id']);
            $table->index(['risk_level', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_notifications');
    }
};
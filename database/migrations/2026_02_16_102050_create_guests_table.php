<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();

            // Identity (normalized)
            $table->string('first_name');
            $table->string('father_name');
            $table->string('last_name');
            $table->string('mother_name');

            $table->string('national_id')->unique();
            $table->string('id_type');
            $table->string('nationality');
            $table->string('car_plate')->nullable();

            // Hashing layer (SHA-256 hex => 64 chars)
            $table->char('national_id_hash', 64)->index();
            $table->char('full_security_hash', 64)->index();

            // Audit / HQ
            $table->enum('audit_status', ['new', 'audited', 'flagged'])->default('new')->index();
            $table->timestamp('audited_at')->nullable();

            $table->foreignId('audited_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('audit_notes')->nullable();

            // Flags & status
            $table->boolean('is_flagged')->default(false);
            $table->string('status')->default('active');

            // Contact
            $table->string('phone');
            $table->string('email')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Indexing (performance)
            $table->index(['audit_status', 'is_flagged']);
            $table->index(['status', 'deleted_at']);
            $table->index(['audited_by', 'audited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
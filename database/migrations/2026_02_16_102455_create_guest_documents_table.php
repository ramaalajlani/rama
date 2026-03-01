<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')
                  ->constrained('guest_reservations')
                  ->cascadeOnDelete();

            $table->foreignId('guest_id')
                  ->constrained('guests')
                  ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');

            // If hash is SHA-256 hex => 64 chars
            $table->char('file_hash', 64)->index();

            $table->string('mime_type');
            $table->unsignedInteger('file_size'); // KB or bytes (choose and keep consistent)

            $table->string('document_type')->default('identity')->index();

            $table->foreignId('uploaded_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Indexing (performance)
            $table->index(['reservation_id', 'document_type']);
            $table->index(['guest_id', 'document_type']);
            $table->index(['uploaded_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_documents');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_blacklists', function (Blueprint $table) {
            $table->id();

            // Hashes
            $table->char('identity_hash', 64)->unique();

            $table->char('full_name_hash', 64)->index();
            $table->char('father_name_hash', 64)->nullable()->index();
            $table->char('mother_name_hash', 64)->nullable()->index();

            $table->char('triple_check_hash', 64)->index();
            $table->char('full_hash', 64)->index();

            $table->string('risk_level')->default('WATCHLIST');
            $table->text('reason')->nullable();
            $table->text('instructions')->nullable();

            $table->foreignId('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes(); // ✅ أضف هذا السطر

            // Indexing
            $table->index(['is_active', 'risk_level']);
            $table->index(['created_by', 'created_at']);
            $table->index('deleted_at'); // اختياري بس مفيد
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_blacklists');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kontak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->nullable()->constrained('klien')->nullOnDelete();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('pengguna')->nullOnDelete();
            
            $table->string('nama');
            $table->string('no_telepon');
            $table->string('email')->nullable();
            $table->json('tags')->nullable();
            $table->enum('source', ['manual', 'import', 'api', 'form'])->default('manual');
            $table->text('catatan')->nullable();
            $table->json('data_tambahan')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('klien_id');
            $table->index('no_telepon');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kontak');
    }
};

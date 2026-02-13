<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('landing_sections')->cascadeOnDelete();
            $table->string('key')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->json('bullets')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_items');
    }
};

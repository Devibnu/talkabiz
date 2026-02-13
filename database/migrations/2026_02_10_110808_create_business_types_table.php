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
        if (!Schema::hasTable('business_types')) {
            Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('UPPERCASE_SNAKE_CASE format');
            $table->string('name', 100)->comment('Display name');
            $table->text('description')->nullable()->comment('Optional description');
            $table->boolean('is_active')->default(true)->comment('Active status');
            $table->integer('display_order')->default(0)->comment('Sort order');
            $table->timestamps();

            // Indexes
            $table->index('is_active');
            $table->index('display_order');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_types');
    }
};

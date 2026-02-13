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
        if (!Schema::hasTable('message_rates')) {
            Schema::create('message_rates', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // text, media, template, campaign, authentication, utility, marketing, service
            $table->string('category')->default('general'); // general, marketing, utility, authentication, service
            $table->decimal('rate_per_message', 8, 2); // Rate in IDR per message
            $table->string('currency', 3)->default('IDR');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Additional rate info (WhatsApp tiers, etc.)
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['type', 'category', 'is_active']);
            $table->index(['is_active', 'effective_from']);
            $table->unique(['type', 'category']); // One rate per type-category combination
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_rates');
    }
};

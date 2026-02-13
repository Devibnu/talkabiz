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
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // midtrans, xendit
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_active')->default(false); // Only 1 can be active
            $table->text('server_key')->nullable(); // Encrypted
            $table->text('client_key')->nullable(); // Encrypted
            $table->text('webhook_secret')->nullable(); // Encrypted (for Xendit)
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->json('settings')->nullable(); // Additional gateway-specific settings
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};

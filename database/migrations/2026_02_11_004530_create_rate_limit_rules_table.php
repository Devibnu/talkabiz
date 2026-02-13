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
        if (!Schema::hasTable('rate_limit_rules')) {
            Schema::create('rate_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('key_pattern')->comment('Pattern untuk matching (e.g., endpoint:*, user:*, api_key:*)');
            $table->enum('context_type', ['global', 'user', 'api_key', 'endpoint', 'ip', 'risk_level', 'saldo_status'])->default('global');
            $table->string('endpoint_pattern')->nullable()->comment('Route pattern (e.g., /api/messages/*)');
            $table->enum('risk_level', ['none', 'low', 'medium', 'high', 'critical'])->nullable();
            $table->enum('saldo_status', ['sufficient', 'low', 'critical', 'zero'])->nullable();
            
            // Rate limit configuration
            $table->integer('max_requests')->comment('Max requests per window');
            $table->integer('window_seconds')->default(60)->comment('Time window in seconds');
            $table->enum('algorithm', ['token_bucket', 'sliding_window'])->default('sliding_window');
            
            // Action on limit exceeded
            $table->enum('action', ['throttle', 'block', 'warn'])->default('throttle');
            $table->integer('throttle_delay_ms')->nullable()->comment('Delay in milliseconds if action=throttle');
            $table->string('block_message')->nullable();
            
            // Priority and activation
            $table->integer('priority')->default(100)->comment('Lower number = higher priority');
            $table->boolean('is_active')->default(true);
            $table->boolean('apply_to_authenticated')->default(true);
            $table->boolean('apply_to_guest')->default(true);
            $table->boolean('bypass_for_owner')->default(false);
            
            // Response headers
            $table->boolean('send_headers')->default(true)->comment('Send X-RateLimit-* headers');
            
            // Metadata
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['context_type', 'is_active']);
            $table->index(['endpoint_pattern', 'is_active']);
            $table->index(['risk_level', 'is_active']);
            $table->index('priority');
        });
}
        
        // Table for rate limit logs (optional, for debugging)
        if (!Schema::hasTable('rate_limit_logs')) {
            Schema::create('rate_limit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('key')->index();
            $table->string('endpoint');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45);
            $table->enum('action_taken', ['allowed', 'throttled', 'blocked', 'warned']);
            $table->integer('current_count');
            $table->integer('limit');
            $table->integer('remaining');
            $table->integer('reset_at')->comment('Unix timestamp');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->foreign('rule_id')->references('id')->on('rate_limit_rules')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_limit_logs');
        Schema::dropIfExists('rate_limit_rules');
    }
};

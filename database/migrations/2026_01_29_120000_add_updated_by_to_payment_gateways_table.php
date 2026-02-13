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
        if (Schema::hasTable('payment_gateways') && !Schema::hasColumn('payment_gateways', 'updated_by')) {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by')->nullable()->after('last_verified_at');
            
            // Index for faster lookup
            $table->index('is_active');
            $table->index('is_enabled');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn('updated_by');
            $table->dropIndex(['is_active']);
            $table->dropIndex(['is_enabled']);
        });
    }
};

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
        if (Schema::hasTable('klien') && !Schema::hasColumn('klien', 'suspended_until')) {
        Schema::table('klien', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable()->after('status');
            $table->string('suspension_reason', 1000)->nullable()->after('suspended_until');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klien', function (Blueprint $table) {
            $table->dropColumn(['suspended_until', 'suspension_reason']);
        });
    }
};

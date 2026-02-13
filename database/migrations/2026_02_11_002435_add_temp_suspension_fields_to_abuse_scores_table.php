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
        if (Schema::hasTable('abuse_scores') && !Schema::hasColumn('abuse_scores', 'suspended_at')) {
        Schema::table('abuse_scores', function (Blueprint $table) {
            // Suspension tracking
            $table->timestamp('suspended_at')->nullable()->after('is_suspended');
            $table->enum('suspension_type', ['none', 'temporary', 'permanent'])
                ->default('none')->after('suspended_at');
            $table->integer('suspension_cooldown_days')->nullable()->after('suspension_type')
                ->comment('Days to wait before auto-unlock for temporary suspension');
            
            // Approval workflow
            $table->enum('approval_status', ['none', 'pending', 'approved', 'rejected', 'auto_approved'])
                ->default('none')->after('suspension_cooldown_days');
            $table->timestamp('approval_status_changed_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approval_changed_by')->nullable()->after('approval_status_changed_at')
                ->comment('User ID who changed approval status');
            
            // Indexes
            $table->index('suspension_type');
            $table->index('approval_status');
            $table->index(['is_suspended', 'suspension_type']);
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('abuse_scores', function (Blueprint $table) {
            $table->dropIndex(['is_suspended', 'suspension_type']);
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['suspension_type']);
            
            $table->dropColumn([
                'suspended_at',
                'suspension_type',
                'suspension_cooldown_days',
                'approval_status',
                'approval_status_changed_at',
                'approval_changed_by',
            ]);
        });
    }
};

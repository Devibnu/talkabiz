<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add approval_status to klien table
 * 
 * PURPOSE:
 * - Track approval status for risk-based business verification
 * - High-risk business types require manual approval
 * - Prevents message sending until approved
 * 
 * STATUS VALUES:
 * - pending: Awaiting approval (default for high-risk)
 * - approved: Can send messages
 * - rejected: Application rejected
 * - suspended: Temporarily suspended
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('klien')) {
            return;
        }

        Schema::table('klien', function (Blueprint $table) {
            if (!Schema::hasColumn('klien', 'approval_status')) {
                $table->enum('approval_status', ['pending', 'approved', 'rejected', 'suspended'])
                    ->default('approved')
                    ->after('status')
                    ->comment('Risk-based approval status for message sending');
            }
            
            if (!Schema::hasColumn('klien', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')
                    ->nullable()
                    ->after('approval_status')
                    ->comment('Admin user ID who approved/rejected');
            }
            
            if (!Schema::hasColumn('klien', 'approved_at')) {
                $table->timestamp('approved_at')
                    ->nullable()
                    ->after('approved_by')
                    ->comment('When approval was granted');
            }
            
            if (!Schema::hasColumn('klien', 'approval_notes')) {
                $table->text('approval_notes')
                    ->nullable()
                    ->after('approved_at')
                    ->comment('Admin notes for approval/rejection');
            }
        });

        // Add indexes safely
        try {
            Schema::table('klien', function (Blueprint $table) {
                $table->index('approval_status', 'idx_approval_status');
                $table->index(['approval_status', 'status'], 'idx_approval_active');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }
        
        // Set existing klien to approved (backward compatibility)
        DB::table('klien')
            ->where('status', 'aktif')
            ->whereNull('approved_at')
            ->update(['approval_status' => 'approved', 'approved_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klien', function (Blueprint $table) {
            $table->dropIndex('idx_approval_status');
            $table->dropIndex('idx_approval_active');
            $table->dropColumn([
                'approval_status',
                'approved_by',
                'approved_at',
                'approval_notes',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Corporate Pilot Flag to Users
 * 
 * Flag untuk mengidentifikasi user yang diundang ke corporate pilot program.
 * Corporate pilot adalah invite-only, tidak public.
 * 
 * RULES:
 * - corporate_pilot = true: User bisa akses fitur corporate
 * - corporate_pilot_invited_at: Kapan diundang
 * - corporate_pilot_invited_by: Siapa yang mengundang (admin)
 * - corporate_pilot_notes: Catatan internal
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'corporate_pilot')) {
        Schema::table('users', function (Blueprint $table) {
            // Corporate pilot flags
            $table->boolean('corporate_pilot')->default(false)->after('risk_level')
                ->comment('User is invited to corporate pilot program');
            
            $table->timestamp('corporate_pilot_invited_at')->nullable()->after('corporate_pilot')
                ->comment('When user was invited to corporate pilot');
            
            $table->unsignedBigInteger('corporate_pilot_invited_by')->nullable()->after('corporate_pilot_invited_at')
                ->comment('Admin who invited the user');
            
            $table->text('corporate_pilot_notes')->nullable()->after('corporate_pilot_invited_by')
                ->comment('Internal notes about corporate pilot user');
            
            // Index for quick filtering
            $table->index('corporate_pilot', 'idx_users_corporate_pilot');
        });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_corporate_pilot');
            $table->dropColumn([
                'corporate_pilot',
                'corporate_pilot_invited_at',
                'corporate_pilot_invited_by',
                'corporate_pilot_notes',
            ]);
        });
    }
};

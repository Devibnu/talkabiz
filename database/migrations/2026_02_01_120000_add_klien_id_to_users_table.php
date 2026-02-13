<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add klien_id to users table
 * 
 * DOMAIN RULE:
 * - Setiap user (kecuali super_admin) HARUS memiliki klien
 * - klien_id adalah foreign key ke tabel klien
 * - Ini memungkinkan wallet dan resource lain terhubung via klien
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add klien_id column after role
            if (!Schema::hasColumn('users', 'klien_id')) {
                $table->unsignedBigInteger('klien_id')->nullable()->after('role');
            }
            
            // Add foreign key constraint
            $table->foreign('klien_id')
                ->references('id')
                ->on('klien')
                ->onDelete('set null');
            
            // Index for faster lookup
            $table->index('klien_id', 'idx_users_klien_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['klien_id']);
            $table->dropIndex('idx_users_klien_id');
            $table->dropColumn('klien_id');
        });
    }
};

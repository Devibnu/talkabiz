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
        Schema::table('users', function (Blueprint $table) {
            // Force password change flag
            if (!Schema::hasColumn('users', 'force_password_change')) {
                $table->boolean('force_password_change')->default(false)->after('password');
            }
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('force_password_change');
            }
            
            // Login tracking
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->integer('failed_login_attempts')->default(0)->after('last_login_ip');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'force_password_change',
                'password_changed_at',
                'last_login_at',
                'last_login_ip',
                'failed_login_attempts',
                'locked_until',
            ]);
        });
    }
};

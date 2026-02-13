<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add plan_snapshot to subscriptions table
 * 
 * CRITICAL: Immutability Pattern
 * 
 * Ketika user subscribe ke paket, harga dan limit di-snapshot.
 * Jika owner mengubah harga paket di kemudian hari,
 * subscription yang sudah ada TIDAK terpengaruh.
 * 
 * Snapshot berisi:
 * - code, name, price, currency
 * - limit_messages_monthly, limit_wa_numbers
 * - features, duration_days
 * - captured_at (timestamp snapshot diambil)
 * 
 * @see SA Document: Modul Paket / Subscription Plan - Section 2.3
 */
return new class extends Migration
{
    public function up(): void
    {
        // Check if subscriptions table exists
        if (!Schema::hasTable('subscriptions')) {
            // Create subscriptions table if not exists
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                
                $table->foreignId('klien_id')
                      ->constrained('klien')
                      ->onDelete('cascade');
                
                $table->foreignId('plan_id')
                      ->nullable()
                      ->constrained('plans')
                      ->onDelete('set null')
                      ->comment('Referensi ke paket (bisa null jika paket dihapus)');
                
                $table->json('plan_snapshot')
                      ->comment('Snapshot paket saat subscribe (immutable)');
                
                $table->decimal('price', 15, 2)
                      ->comment('Harga yang dibayar (dari snapshot)');
                
                $table->string('currency', 3)->default('IDR');
                
                $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])
                      ->default('pending');
                
                $table->timestamp('started_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                
                $table->timestamps();
                $table->softDeletes();
                
                // Indexes
                $table->index('klien_id');
                $table->index('plan_id');
                $table->index('status');
                $table->index('expires_at');
            });
        } else {
            // Alter existing subscriptions table
            Schema::table('subscriptions', function (Blueprint $table) {
                // Add plan_id if not exists
                if (!Schema::hasColumn('subscriptions', 'plan_id')) {
                    $table->foreignId('plan_id')
                          ->nullable()
                          ->after('id')
                          ->constrained('plans')
                          ->onDelete('set null');
                }
                
                // Add plan_snapshot if not exists
                if (!Schema::hasColumn('subscriptions', 'plan_snapshot')) {
                    $table->json('plan_snapshot')
                          ->nullable()
                          ->after('plan_id')
                          ->comment('Snapshot paket saat subscribe (immutable)');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('subscriptions', 'plan_snapshot')) {
                    $table->dropColumn('plan_snapshot');
                }
                if (Schema::hasColumn('subscriptions', 'plan_id')) {
                    $table->dropForeign(['plan_id']);
                    $table->dropColumn('plan_id');
                }
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add immutable business snapshot untuk audit trail dan PPN.
     * Snapshot ini tidak berubah walaupun profil bisnis diupdate.
     */
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'billing_snapshot')) {
                $table->json('billing_snapshot')->nullable()->after('metadata')
                    ->comment('Snapshot legal business data pada saat invoice created (immutable)');
            }
            
            if (!Schema::hasColumn('invoices', 'snapshot_business_name')) {
                $table->string('snapshot_business_name')->nullable()->after('billing_snapshot')
                    ->comment('Quick access: nama bisnis dari snapshot');
            }

            if (!Schema::hasColumn('invoices', 'snapshot_business_type')) {
                $table->string('snapshot_business_type')->nullable()->after('snapshot_business_name')
                    ->comment('Quick access: tipe bisnis code dari snapshot');
            }

            if (!Schema::hasColumn('invoices', 'snapshot_npwp')) {
                $table->string('snapshot_npwp', 20)->nullable()->after('snapshot_business_type')
                    ->comment('Quick access: NPWP dari snapshot');
            }
        });

        // Add indexes safely
        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('snapshot_business_type', 'idx_invoices_business_type');
                $table->index(['snapshot_npwp', 'created_at'], 'idx_invoices_npwp_date');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_business_type');
            $table->dropIndex('idx_invoices_npwp_date');
            $table->dropColumn([
                'billing_snapshot',
                'snapshot_business_name',
                'snapshot_business_type',
                'snapshot_npwp',
            ]);
        });
    }
};

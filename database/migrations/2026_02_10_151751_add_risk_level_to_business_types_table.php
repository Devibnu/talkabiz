<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add risk management fields:
     * - risk_level: Categorize business types by risk
     * - minimum_balance_buffer: Required safety buffer for medium/high risk
     * - requires_manual_approval: Flag for high-risk transactions
     */
    public function up(): void
    {
        if (Schema::hasTable('business_types') && !Schema::hasColumn('business_types', 'risk_level')) {
        Schema::table('business_types', function (Blueprint $table) {
            $table->enum('risk_level', ['low', 'medium', 'high'])
                ->default('medium')
                ->after('pricing_multiplier')
                ->comment('Risk categorization for fraud prevention');
            
            $table->integer('minimum_balance_buffer')
                ->default(0)
                ->after('risk_level')
                ->comment('Minimum balance buffer (IDR) required for transactions');
            
            $table->boolean('requires_manual_approval')
                ->default(false)
                ->after('minimum_balance_buffer')
                ->comment('High-risk: Requires manual approval for large transactions');
            
            // Indexes for risk queries
            $table->index(['risk_level', 'is_active']);
            $table->index('requires_manual_approval');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_types', function (Blueprint $table) {
            $table->dropIndex(['risk_level', 'is_active']);
            $table->dropIndex(['requires_manual_approval']);
            
            $table->dropColumn('risk_level');
            $table->dropColumn('minimum_balance_buffer');
            $table->dropColumn('requires_manual_approval');
        });
    }
};

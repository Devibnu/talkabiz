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
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'wallet_transaction_id')) {
                $table->foreignId('wallet_transaction_id')
                      ->nullable()
                      ->after('invoiceable_type')
                      ->constrained('wallet_transactions')
                      ->onDelete('set null')
                      ->comment('Link to wallet_transactions for topup invoices');
            }
        });

        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('wallet_transaction_id');
            });
        } catch (\Exception $e) {
            // Index already exists
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['wallet_transaction_id']);
            $table->dropColumn('wallet_transaction_id');
        });
    }
};

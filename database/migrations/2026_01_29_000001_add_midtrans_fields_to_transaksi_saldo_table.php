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
        if (Schema::hasTable('transaksi_saldo') && !Schema::hasColumn('transaksi_saldo', 'midtrans_snap_token')) {
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            // Midtrans specific fields
            $table->string('midtrans_snap_token', 255)->nullable()->after('referensi');
            $table->json('midtrans_response')->nullable()->after('midtrans_snap_token');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            $table->dropColumn(['midtrans_snap_token', 'midtrans_response']);
        });
    }
};

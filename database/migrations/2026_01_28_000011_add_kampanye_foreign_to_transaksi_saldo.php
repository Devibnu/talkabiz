<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan foreign key constraint untuk transaksi_saldo.kampanye_id
     * Dipisah karena tabel kampanye dibuat setelah transaksi_saldo
     */
    public function up(): void
    {
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            $table->foreign('kampanye_id')
                  ->references('id')
                  ->on('kampanye')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            $table->dropForeign(['kampanye_id']);
        });
    }
};

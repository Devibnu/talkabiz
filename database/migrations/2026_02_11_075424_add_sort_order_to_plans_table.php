<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('sort_order')->default(0)->after('is_popular');
        });

        // Set default sort order by price
        DB::table('plans')->orderBy('price_monthly')->get()->each(function ($plan, $index) {
            DB::table('plans')->where('id', $plan->id)->update(['sort_order' => ($index + 1) * 10]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

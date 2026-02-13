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
        if (!Schema::hasTable('tax_reports')) {
            Schema::create('tax_reports', function (Blueprint $table) {
            $table->id();
            
            // Period (unique year + month)
            $table->integer('year');
            $table->integer('month');
            
            // Aggregated data
            $table->integer('total_invoices')->default(0);
            $table->decimal('total_dpp', 15, 2)->default(0);
            $table->decimal('total_ppn', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(11.00);
            
            // Status & metadata
            $table->enum('status', ['draft', 'final'])->default('draft');
            $table->json('metadata')->nullable();
            $table->string('report_hash')->nullable();
            
            // Generation tracking
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            
            $table->timestamps();
            
            // Indexes & constraints
            $table->unique(['year', 'month'], 'unique_period');
            $table->index(['year', 'month', 'status']);
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_reports');
    }
};

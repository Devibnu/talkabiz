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
        // ==================== TAX SETTINGS TABLE ====================
        if (!Schema::hasTable('tax_settings')) {
            Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['global', 'client'])->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('setting_key', 100)->index(); // 'ppn_rate', 'pph_rate', 'default_tax_included', etc.
            $table->text('setting_value');
            $table->string('value_type', 20)->default('decimal'); // decimal, boolean, string, json
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Constraints
            $table->unique(['scope', 'client_id', 'setting_key'], 'tax_settings_unique_scope');
            
            // Foreign keys
            $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['scope', 'is_active']);
            $table->index(['client_id', 'is_active']);
        });
}

        // ==================== COMPANY PROFILES TABLE ====================
        if (!Schema::hasTable('company_profiles')) {
            Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique(); // Owner of the company
            $table->string('company_name');
            $table->string('company_code', 20)->unique()->nullable(); // Internal code
            $table->text('address');
            $table->string('city', 100);
            $table->string('postal_code', 10);
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            
            // Tax Information
            $table->string('npwp', 20)->nullable(); // Nomor Pokok Wajib Pajak
            $table->boolean('is_pkp')->default(false); // Pengusaha Kena Pajak
            $table->string('pkp_number', 50)->nullable(); // Nomor PKP
            $table->date('pkp_date')->nullable(); // Tanggal PKP
            
            // Banking Information
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 30)->nullable();
            $table->string('bank_account_name')->nullable();
            
            // Logo & Branding
            $table->string('logo_path')->nullable();
            $table->string('signature_path')->nullable();
            
            // Invoice Settings
            $table->string('invoice_prefix', 10)->default('INV');
            $table->integer('invoice_counter')->default(0);
            $table->string('invoice_number_format', 50)->default('{prefix}/{year}/{month}/{counter}');
            
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
}

        // ==================== TAX RULES TABLE ====================
        if (!Schema::hasTable('tax_rules')) {
            Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code', 50)->unique();
            $table->string('rule_name');
            $table->text('description')->nullable();
            $table->enum('tax_type', ['ppn', 'pph21', 'pph22', 'pph23', 'pph4ayat2', 'custom'])->index();
            $table->decimal('rate', 8, 4); // Support up to 99.9999%
            $table->decimal('minimum_amount', 15, 2)->default(0); // Minimum amount to apply tax
            $table->decimal('maximum_amount', 15, 2)->nullable(); // Maximum amount (optional)
            $table->boolean('is_inclusive')->default(false); // Tax included in price
            $table->json('calculation_rules')->nullable(); // Complex calculation rules
            $table->boolean('is_active')->default(true)->index();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // Indexes
            $table->index(['tax_type', 'is_active']);
            $table->index(['effective_from', 'effective_until']);
            
            // Foreign key
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
}

        // ==================== CLIENT TAX CONFIGURATIONS TABLE ====================
        if (!Schema::hasTable('client_tax_configurations')) {
            Schema::create('client_tax_configurations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('tax_rule_id');
            $table->boolean('is_enabled')->default(true)->index();
            $table->decimal('custom_rate', 8, 4)->nullable(); // Override default rate
            $table->json('custom_settings')->nullable(); // Custom configuration per client
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('configured_by');
            $table->timestamps();

            // Constraints
            $table->unique(['client_id', 'tax_rule_id'], 'client_tax_unique');
            
            // Foreign keys
            $table->foreign('client_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tax_rule_id')->references('id')->on('tax_rules')->onDelete('cascade');
            $table->foreign('configured_by')->references('id')->on('users')->onDelete('cascade');
        });
}

        // Seed default tax rules
        $this->seedDefaultTaxRules();
        $this->seedDefaultTaxSettings();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_tax_configurations');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('company_profiles');
        Schema::dropIfExists('tax_settings');
    }

    /**
     * Seed default tax rules for Indonesia
     */
    private function seedDefaultTaxRules(): void
    {
        DB::table('tax_rules')->insertOrIgnore([
            [
                'rule_code' => 'PPN_STANDARD',
                'rule_name' => 'PPN Standar 11%',
                'description' => 'Pajak Pertambahan Nilai standar Indonesia',
                'tax_type' => 'ppn',
                'rate' => 11.00,
                'minimum_amount' => 0.00,
                'maximum_amount' => null,
                'is_inclusive' => false,
                'calculation_rules' => json_encode([
                    'rounding' => 'round',
                    'precision' => 2
                ]),
                'is_active' => true,
                'effective_from' => '2022-04-01',
                'effective_until' => null,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'rule_code' => 'PPH21_STANDARD',
                'rule_name' => 'PPh 21 Standar',
                'description' => 'Pajak Penghasilan Pasal 21',
                'tax_type' => 'pph21',
                'rate' => 5.00,
                'minimum_amount' => 0.00,
                'maximum_amount' => null,
                'is_inclusive' => false,
                'calculation_rules' => json_encode([
                    'rounding' => 'round',
                    'precision' => 2
                ]),
                'is_active' => true,
                'effective_from' => '2020-01-01',
                'effective_until' => null,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'rule_code' => 'PPH23_JASA',
                'rule_name' => 'PPh 23 Jasa Teknik',
                'description' => 'PPh 23 untuk jasa teknik, konsultasi, dll',
                'tax_type' => 'pph23',
                'rate' => 2.00,
                'minimum_amount' => 2500000.00,
                'maximum_amount' => null,
                'is_inclusive' => false,
                'calculation_rules' => json_encode([
                    'rounding' => 'round',
                    'precision' => 2,
                    'apply_if_above_minimum' => true
                ]),
                'is_active' => true,
                'effective_from' => '2020-01-01',
                'effective_until' => null,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'rule_code' => 'NO_TAX',
                'rule_name' => 'Tanpa Pajak',
                'description' => 'Tidak dikenakan pajak',
                'tax_type' => 'custom',
                'rate' => 0.00,
                'minimum_amount' => 0.00,
                'maximum_amount' => null,
                'is_inclusive' => false,
                'calculation_rules' => json_encode([]),
                'is_active' => true,
                'effective_from' => '2020-01-01',
                'effective_until' => null,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Seed default global tax settings
     */
    private function seedDefaultTaxSettings(): void
    {
        // Only seed if tax_settings has the expected schema (setting_key column)
        if (!Schema::hasColumn('tax_settings', 'setting_key')) {
            return;
        }

        $defaultSettings = [
            ['setting_key' => 'default_ppn_rate', 'setting_value' => '11.00', 'value_type' => 'decimal', 'description' => 'Default PPN rate'],
            ['setting_key' => 'ppn_inclusive_default', 'setting_value' => 'false', 'value_type' => 'boolean', 'description' => 'PPN included in price by default'],
            ['setting_key' => 'auto_calculate_tax', 'setting_value' => 'true', 'value_type' => 'boolean', 'description' => 'Automatically calculate tax'],
            ['setting_key' => 'tax_rounding_mode', 'setting_value' => 'round', 'value_type' => 'string', 'description' => 'Tax rounding mode: round, floor, ceil'],
            ['setting_key' => 'tax_calculation_precision', 'setting_value' => '2', 'value_type' => 'integer', 'description' => 'Decimal precision for tax calculations'],
            ['setting_key' => 'invoice_tax_display', 'setting_value' => 'breakdown', 'value_type' => 'string', 'description' => 'Tax display mode: breakdown, inclusive, exclusive'],
            ['setting_key' => 'require_npwp_for_invoice', 'setting_value' => 'false', 'value_type' => 'boolean', 'description' => 'Require NPWP for invoice generation'],
            ['setting_key' => 'minimum_invoice_amount', 'setting_value' => '0.00', 'value_type' => 'decimal', 'description' => 'Minimum amount for invoice generation'],
        ];

        foreach ($defaultSettings as $setting) {
            DB::table('tax_settings')->insertOrIgnore(array_merge($setting, [
                'scope' => 'global',
                'client_id' => null,
                'is_active' => true,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]));
        }
    }
};
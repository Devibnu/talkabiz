<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop dead feature tables — Batch 2
 *
 * Audit confirmation:
 *   - plan_cost_estimates  → PlanCostEstimate model never used anywhere
 *   - wa_health_scores     → WaHealthScore model only relationship skeleton
 *   - wa_health_logs       → WaHealthLog model only relationship skeleton
 *   - wa_risk_events       → WaRiskEvent model only relationship skeleton
 *   - corporate_contracts  → CorporateContract model never used in controller/service
 *
 * All tables have 0 rows.
 */
return new class extends Migration
{
    private const TABLES = [
        'plan_cost_estimates',
        'wa_health_scores',
        'wa_health_logs',
        'wa_risk_events',
        'corporate_contracts',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        // Intentionally left empty.
        // These were dead tables with 0 rows — no rollback needed.
        // Re-create from original migrations if ever required.
    }
};

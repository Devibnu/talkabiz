<?php

use App\Http\Controllers\Owner\OwnerDashboardController;
use App\Http\Controllers\Owner\OwnerClientController;
use App\Http\Controllers\Owner\OwnerWhatsAppController;
use App\Http\Controllers\Owner\OwnerWhatsAppConnectionController;
use App\Http\Controllers\Owner\OwnerUserController;
use App\Http\Controllers\Owner\OwnerBillingController;
use App\Http\Controllers\Owner\OwnerLogController;
use App\Http\Controllers\Owner\OwnerPaymentGatewayController;
use App\Http\Controllers\Owner\OwnerPlanController;
use App\Http\Controllers\Owner\OwnerLandingController;
use App\Http\Controllers\Owner\OwnerBrandingController;
use App\Http\Controllers\Owner\OwnerTaxReportController;
use App\Http\Controllers\Owner\OwnerMonthlyClosingController;
use App\Http\Controllers\Owner\OwnerReconciliationController;
use App\Http\Controllers\Owner\OwnerAuditTrailController;
use App\Http\Controllers\Owner\OwnerCfoDashboardController;
use App\Http\Controllers\Owner\OwnerBusinessTypeController;
use App\Http\Controllers\Owner\OwnerSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Owner Routes
|--------------------------------------------------------------------------
|
| Routes khusus untuk Owner/Super Admin platform.
| Semua route di sini memiliki prefix /owner dan dilindungi middleware:
| - auth (harus login)
| - ensure.owner (harus role owner/super_admin)
| - force.password.change (wajib ganti password jika dipaksa)
|
*/

Route::middleware(['auth', 'ensure.owner', 'force.password.change'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {

        // ==================== DASHBOARD ====================
        Route::get('/', [OwnerDashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard', [OwnerDashboardController::class, 'index'])->name('dashboard.index');

        // ==================== CLIENT MANAGEMENT ====================
        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', [OwnerClientController::class, 'index'])->name('index');
            Route::get('/{klien}', [OwnerClientController::class, 'show'])->name('show');
            Route::post('/{klien}/approve', [OwnerClientController::class, 'approve'])->name('approve');
            Route::post('/{klien}/suspend', [OwnerClientController::class, 'suspend'])->name('suspend');
            Route::post('/{klien}/activate', [OwnerClientController::class, 'activate'])->name('activate');
            Route::post('/{klien}/reset-quota', [OwnerClientController::class, 'resetQuota'])->name('reset-quota');
        });

        // ==================== WHATSAPP CONTROL (Legacy) ====================
        Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
            Route::get('/', [OwnerWhatsAppController::class, 'index'])->name('index');
            Route::get('/{connection}', [OwnerWhatsAppController::class, 'show'])->name('show');
            Route::post('/{connection}/force-connect', [OwnerWhatsAppController::class, 'forceConnect'])->name('force-connect');
            Route::post('/{connection}/force-fail', [OwnerWhatsAppController::class, 'forceFail'])->name('force-fail');
            Route::post('/{connection}/force-pending', [OwnerWhatsAppController::class, 'forcePending'])->name('force-pending');
            Route::post('/{connection}/disconnect', [OwnerWhatsAppController::class, 'disconnect'])->name('disconnect');
            Route::post('/{connection}/re-verify', [OwnerWhatsAppController::class, 'reVerify'])->name('re-verify');
            
            // New hardened endpoints
            Route::post('/{connection}/force-disconnect', [OwnerWhatsAppController::class, 'forceDisconnect'])->name('force-disconnect');
            Route::post('/{connection}/ban', [OwnerWhatsAppController::class, 'banWhatsapp'])->name('ban');
            Route::post('/{connection}/unban', [OwnerWhatsAppController::class, 'unbanWhatsapp'])->name('unban');
        });

        // ==================== USER MANAGEMENT ====================
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [OwnerUserController::class, 'index'])->name('index');
            Route::get('/{user}', [OwnerUserController::class, 'show'])->name('show');
            Route::post('/{user}/ban', [OwnerUserController::class, 'ban'])->name('ban');
            Route::post('/{user}/unban', [OwnerUserController::class, 'unban'])->name('unban');
            Route::post('/{user}/suspend', [OwnerUserController::class, 'suspend'])->name('suspend');
        });

        // ==================== WHATSAPP CONNECTIONS (New - Webhook Source of Truth) ====================
        // Status CONNECTED hanya dari webhook, owner hanya bisa disconnect/reset
        Route::prefix('whatsapp-connections')->name('wa-connections.')->group(function () {
            // List all connections
            Route::get('/', [OwnerWhatsAppConnectionController::class, 'index'])
                ->name('index');
            
            // View single connection detail
            Route::get('/{id}', [OwnerWhatsAppConnectionController::class, 'show'])
                ->name('show');
            
            // View webhook history
            Route::get('/{id}/webhook-history', [OwnerWhatsAppConnectionController::class, 'webhookHistory'])
                ->name('webhook-history');
            
            // Force disconnect (owner only action)
            Route::post('/{id}/force-disconnect', [OwnerWhatsAppConnectionController::class, 'forceDisconnect'])
                ->name('force-disconnect');
            
            // Reset to PENDING for retry
            Route::post('/{id}/reset-to-pending', [OwnerWhatsAppConnectionController::class, 'resetToPending'])
                ->name('reset-to-pending');
            
            // Refresh status (read-only, clears cache)
            Route::post('/{id}/refresh-status', [OwnerWhatsAppConnectionController::class, 'refreshStatus'])
                ->name('refresh-status');
        });

        // ==================== BILLING OVERVIEW ====================
        Route::prefix('billing')->name('billing.')->group(function () {
            Route::get('/', [OwnerBillingController::class, 'index'])->name('index');
            Route::get('/revenue', [OwnerBillingController::class, 'revenue'])->name('revenue');
            Route::get('/transactions', [OwnerBillingController::class, 'transactions'])->name('transactions');
        });

        // ==================== CFO DASHBOARD ====================
        Route::prefix('cfo-dashboard')->name('cfo.')->group(function () {
            Route::get('/', [OwnerCfoDashboardController::class, 'index'])->name('index');
            Route::get('/data', [OwnerCfoDashboardController::class, 'data'])->name('data');
            Route::get('/validation', [OwnerCfoDashboardController::class, 'validation'])->name('validation');
            Route::get('/mismatch-report', [OwnerCfoDashboardController::class, 'mismatchReport'])->name('mismatch-report');
        });

        // ==================== LAPORAN PAJAK (PPN) ====================
        Route::prefix('tax-report')->name('tax-report.')->group(function () {
            Route::get('/', [OwnerTaxReportController::class, 'index'])->name('index');
            Route::post('/generate', [OwnerTaxReportController::class, 'generate'])->name('generate');
            Route::get('/{year}/{month}', [OwnerTaxReportController::class, 'show'])->name('show');
            Route::get('/{year}/{month}/pdf', [OwnerTaxReportController::class, 'downloadPdf'])->name('pdf');
            Route::get('/{year}/{month}/csv', [OwnerTaxReportController::class, 'exportCsv'])->name('csv');
            Route::post('/{year}/{month}/finalize', [OwnerTaxReportController::class, 'finalize'])->name('finalize');
            Route::post('/{year}/{month}/reopen', [OwnerTaxReportController::class, 'reopen'])->name('reopen');
        });

        // ==================== MONTHLY CLOSING & REKONSILIASI ====================
        Route::prefix('closing')->name('closing.')->group(function () {
            Route::get('/', [OwnerMonthlyClosingController::class, 'index'])->name('index');
            Route::post('/preview', [OwnerMonthlyClosingController::class, 'preview'])->name('preview');
            Route::post('/close', [OwnerMonthlyClosingController::class, 'close'])->name('close');
            Route::get('/{year}/{month}', [OwnerMonthlyClosingController::class, 'show'])->name('show');
            Route::get('/{year}/{month}/pdf', [OwnerMonthlyClosingController::class, 'downloadPdf'])->name('pdf');
            Route::post('/{year}/{month}/finalize', [OwnerMonthlyClosingController::class, 'finalize'])->name('finalize');
            Route::post('/{year}/{month}/reopen', [OwnerMonthlyClosingController::class, 'reopen'])->name('reopen');
        });

        // ==================== REKONSILIASI BANK & GATEWAY ====================
        Route::prefix('reconciliation')->name('reconciliation.')->group(function () {
            Route::get('/', [OwnerReconciliationController::class, 'index'])->name('index');
            Route::post('/preview-gateway', [OwnerReconciliationController::class, 'previewGateway'])->name('preview-gateway');
            Route::post('/preview-bank', [OwnerReconciliationController::class, 'previewBank'])->name('preview-bank');
            Route::post('/reconcile-gateway', [OwnerReconciliationController::class, 'reconcileGateway'])->name('reconcile-gateway');
            Route::post('/reconcile-bank', [OwnerReconciliationController::class, 'reconcileBank'])->name('reconcile-bank');
            Route::post('/import-bank', [OwnerReconciliationController::class, 'importBankStatements'])->name('import-bank');
            Route::post('/add-bank-statement', [OwnerReconciliationController::class, 'addBankStatement'])->name('add-bank-statement');
            Route::get('/{year}/{month}/{source}', [OwnerReconciliationController::class, 'show'])->name('show');
            Route::post('/{year}/{month}/{source}/mark-ok', [OwnerReconciliationController::class, 'markOk'])->name('mark-ok');
            Route::get('/{year}/{month}/{source}/export-csv', [OwnerReconciliationController::class, 'exportCsv'])->name('export-csv');
        });

        // ==================== LOGS & AUDIT ====================
        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [OwnerLogController::class, 'index'])->name('index');
            Route::get('/activity', [OwnerLogController::class, 'activity'])->name('activity');
            Route::get('/webhooks', [OwnerLogController::class, 'webhooks'])->name('webhooks');
            Route::get('/messages', [OwnerLogController::class, 'messages'])->name('messages');
        });

        // ==================== AUDIT TRAIL & IMMUTABLE LEDGER ====================
        Route::prefix('audit-trail')->name('audit-trail.')->group(function () {
            Route::get('/', [OwnerAuditTrailController::class, 'index'])->name('index');
            Route::get('/integrity', [OwnerAuditTrailController::class, 'integrityCheck'])->name('integrity');
            Route::get('/export-csv', [OwnerAuditTrailController::class, 'exportCsv'])->name('export-csv');
            Route::get('/entity/{entityType}/{entityId}', [OwnerAuditTrailController::class, 'entityHistory'])->name('entity-history');
            Route::get('/{id}', [OwnerAuditTrailController::class, 'show'])->name('show')->whereNumber('id');
        });

        // ==================== PAYMENT GATEWAY MANAGEMENT ====================
        Route::prefix('payment-gateway')->name('payment-gateway.')->group(function () {
            Route::get('/', [OwnerPaymentGatewayController::class, 'index'])->name('index');
            Route::put('/{gateway}', [OwnerPaymentGatewayController::class, 'update'])->name('update');
            Route::put('/{gateway}/set-active', [OwnerPaymentGatewayController::class, 'setActive'])->name('set-active');
            Route::put('/{gateway}/deactivate', [OwnerPaymentGatewayController::class, 'deactivate'])->name('deactivate');
            Route::post('/{gateway}/test', [OwnerPaymentGatewayController::class, 'testConnection'])->name('test');
            Route::get('/status', [OwnerPaymentGatewayController::class, 'getStatus'])->name('status');
        });

        // ==================== PLAN MANAGEMENT (SSOT) ====================
        // Single Source of Truth untuk paket langganan
        Route::prefix('plans')->name('plans.')->group(function () {
            // List all plans
            Route::get('/', [OwnerPlanController::class, 'index'])->name('index');
            
            // Create new plan
            Route::get('/create', [OwnerPlanController::class, 'create'])->name('create');
            Route::post('/', [OwnerPlanController::class, 'store'])->name('store');
            
            // View plan detail with audit log
            Route::get('/{plan}', [OwnerPlanController::class, 'show'])->name('show');
            
            // Edit plan
            Route::get('/{plan}/edit', [OwnerPlanController::class, 'edit'])->name('edit');
            Route::put('/{plan}', [OwnerPlanController::class, 'update'])->name('update');
            
            // Toggle actions (AJAX)
            Route::match(['patch', 'post'], '/{plan}/toggle-active', [OwnerPlanController::class, 'toggleActive'])->name('toggle-active');
            Route::match(['patch', 'post'], '/{plan}/toggle-popular', [OwnerPlanController::class, 'togglePopular'])->name('toggle-popular');
        });

        // ==================== BRANDING & LOGO (SSOT) ====================
        Route::prefix('branding')->name('branding.')->group(function () {
            Route::get('/', [OwnerBrandingController::class, 'index'])->name('index');
            Route::put('/info', [OwnerBrandingController::class, 'updateInfo'])->name('update-info');
            Route::post('/logo', [OwnerBrandingController::class, 'uploadLogo'])->name('upload-logo');
            Route::delete('/logo', [OwnerBrandingController::class, 'removeLogo'])->name('remove-logo');
            Route::post('/favicon', [OwnerBrandingController::class, 'uploadFavicon'])->name('upload-favicon');
            Route::delete('/favicon', [OwnerBrandingController::class, 'removeFavicon'])->name('remove-favicon');
        });

        // ==================== LANDING PAGE CMS ====================
        Route::prefix('landing')->name('landing.')->group(function () {
            Route::get('/', [OwnerLandingController::class, 'index'])->name('index');
            Route::get('/sections/{section}/edit', [OwnerLandingController::class, 'editSection'])->name('sections.edit');
            Route::put('/sections/{section}', [OwnerLandingController::class, 'updateSection'])->name('sections.update');

            Route::get('/items/{item}/edit', [OwnerLandingController::class, 'editItem'])->name('items.edit');
            Route::put('/items/{item}', [OwnerLandingController::class, 'updateItem'])->name('items.update');
        });

        // ==================== WARMUP STATE MACHINE MANAGEMENT ====================
        Route::prefix('warmup')->name('warmup.')->group(function () {
            // Dashboard view
            Route::get('/', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'index'])
                ->name('index');
            
            // Force cooldown
            Route::post('/{warmup}/force-cooldown', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'forceCooldown'])
                ->name('force-cooldown');
            
            // Resume from cooldown/suspended
            Route::post('/{warmup}/resume', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'resume'])
                ->name('resume');
            
            // History endpoints
            Route::get('/{warmup}/history/states', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'stateHistory'])
                ->name('history.states');
            Route::get('/{warmup}/history/limits', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'limitHistory'])
                ->name('history.limits');
            Route::get('/{warmup}/history/blocks', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'blockHistory'])
                ->name('history.blocks');
            
            // Status API for single connection
            Route::get('/connection/{connection}/status', [\App\Http\Controllers\Owner\OwnerWarmupController::class, 'getStatus'])
                ->name('connection.status');
        });

        // ==================== ENHANCED PRICING CONTROL ====================
        Route::prefix('pricing-control')->name('pricing.')->group(function () {
            // Dashboard view
            Route::get('/', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'control'])
                ->name('control');
            
            // Update meta cost
            Route::post('/update-cost', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'updateCost'])
                ->name('update-cost');
            
            // Override category price
            Route::post('/override', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'override'])
                ->name('override');
            
            // Unlock category price
            Route::post('/unlock', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'unlock'])
                ->name('unlock');
            
            // Update settings
            Route::put('/settings', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'updateSettings'])
                ->name('update-settings');
            
            // Recalculate all
            Route::post('/recalculate', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'recalculate'])
                ->name('recalculate');
            
            // Resolve alert
            Route::post('/resolve-alert/{id}', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'resolveAlert'])
                ->name('resolve-alert');
            
            // Re-evaluate risk
            Route::post('/reevaluate-risk/{id}', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'reevaluateRisk'])
                ->name('reevaluate-risk');
            
            // API Summary
            Route::get('/summary', [\App\Http\Controllers\Owner\EnhancedPricingController::class, 'summary'])
                ->name('summary');
        });

        // ==================== GO-LIVE CHECKLIST ====================
        Route::prefix('golive')->name('golive.')->group(function () {
            // Dashboard view
            Route::get('/', [\App\Http\Controllers\Owner\GoLiveController::class, 'index'])
                ->name('index');
            
            // Refresh checks
            Route::post('/refresh', [\App\Http\Controllers\Owner\GoLiveController::class, 'refresh'])
                ->name('refresh');
            
            // Run artisan command
            Route::post('/run-command', [\App\Http\Controllers\Owner\GoLiveController::class, 'runCommand'])
                ->name('run-command');
        });

        // ==================== DAILY OPS (H+1 to H+7 Post Go-Live) ====================
        Route::prefix('ops')->name('ops.')->group(function () {
            // Main dashboard
            Route::get('/', [\App\Http\Controllers\Owner\DailyOpsController::class, 'index'])
                ->name('index');
            
            // Run daily check
            Route::post('/run-check', [\App\Http\Controllers\Owner\DailyOpsController::class, 'runCheck'])
                ->name('run-check');
            
            // Day-specific details
            Route::get('/day/{day}', [\App\Http\Controllers\Owner\DailyOpsController::class, 'dayDetails'])
                ->name('day-details')
                ->whereNumber('day');
            
            // Week summary
            Route::get('/week-summary', [\App\Http\Controllers\Owner\DailyOpsController::class, 'weekSummary'])
                ->name('week-summary');
            
            // Action items
            Route::get('/action-items', [\App\Http\Controllers\Owner\DailyOpsController::class, 'actionItems'])
                ->name('action-items');
            Route::post('/action-items', [\App\Http\Controllers\Owner\DailyOpsController::class, 'createActionItem'])
                ->name('create-action-item');
            Route::put('/action-items/{id}', [\App\Http\Controllers\Owner\DailyOpsController::class, 'updateActionItem'])
                ->name('update-action-item');
            
            // Risk events
            Route::get('/risk-events', [\App\Http\Controllers\Owner\DailyOpsController::class, 'riskEvents'])
                ->name('risk-events');
            Route::post('/risk-events/{id}/mitigate', [\App\Http\Controllers\Owner\DailyOpsController::class, 'mitigateRisk'])
                ->name('mitigate-risk');
            
            // Owner decision
            Route::get('/decision', [\App\Http\Controllers\Owner\DailyOpsController::class, 'decision'])
                ->name('decision');
            Route::post('/decision', [\App\Http\Controllers\Owner\DailyOpsController::class, 'submitDecision'])
                ->name('submit-decision');
        });

        // ==================== SYSTEM SETTINGS (SSOT) ====================
        // Core system config — terpisah dari Landing CMS
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [OwnerSettingController::class, 'index'])->name('index');
            Route::put('/', [OwnerSettingController::class, 'update'])->name('update');
        });

        // ==================== MASTER DATA - BUSINESS TYPES ====================
        Route::prefix('master/business-types')->name('master.business-types.')->group(function () {
            Route::get('/', [OwnerBusinessTypeController::class, 'index'])->name('index');
            Route::get('/create', [OwnerBusinessTypeController::class, 'create'])->name('create');
            Route::post('/', [OwnerBusinessTypeController::class, 'store'])->name('store');
            Route::get('/{businessType}/edit', [OwnerBusinessTypeController::class, 'edit'])->name('edit');
            Route::put('/{businessType}', [OwnerBusinessTypeController::class, 'update'])->name('update');
            Route::post('/{businessType}/toggle-active', [OwnerBusinessTypeController::class, 'toggleActive'])->name('toggle-active');
        });
    });


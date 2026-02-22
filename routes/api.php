<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\InboxSendController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Webhook\GupshupWhatsAppNumberController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\SlaAwareSupportController;
use App\Http\Controllers\Api\SlaDashboardController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Midtrans Webhook Routes
|--------------------------------------------------------------------------
|
| Endpoint untuk menerima notification dari Midtrans.
| CRITICAL: Tidak menggunakan auth middleware (dipanggil oleh Midtrans)
|
*/

Route::prefix('midtrans')->group(function () {
    // Midtrans Notification Webhook
    Route::post('/webhook', [MidtransWebhookController::class, 'handle'])
        ->name('midtrans.webhook');
    
    // Check transaction status (internal, requires auth)
    Route::middleware('auth:sanctum')->get('/status/{orderId}', [MidtransWebhookController::class, 'checkStatus'])
        ->name('midtrans.status');
});

/*
|--------------------------------------------------------------------------
| Xendit Webhook Routes
|--------------------------------------------------------------------------
|
| Endpoint untuk menerima notification dari Xendit.
| CRITICAL: Tidak menggunakan auth middleware (dipanggil oleh Xendit)
|
*/

Route::prefix('xendit')->group(function () {
    // Xendit Invoice Webhook (PAID, EXPIRED, etc.)
    Route::post('/webhook', [XenditWebhookController::class, 'handle'])
        ->name('xendit.webhook');
    
    // Check invoice status (internal, requires auth)
    Route::middleware('auth:sanctum')->get('/status/{invoiceId}', [XenditWebhookController::class, 'checkStatus'])
        ->name('xendit.status');
});

/*
|--------------------------------------------------------------------------
| Billing API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('billing')->group(function () {
    // ==================== CLIENT-ONLY BILLING API ====================
    // Topup routes — Owner/Admin BLOCKED (ensure.client)
    Route::middleware('ensure.client')->group(function () {
        // Unified Top Up - Auto-routes to active gateway (RECOMMENDED)
        Route::post('/topup', [BillingController::class, 'topUpUnified'])
            ->name('billing.topup-unified');
        
        // Gateway-specific routes (legacy, still supported)
        Route::post('/topup-midtrans', [BillingController::class, 'topUpMidtrans'])
            ->name('billing.topup-midtrans');
        
        Route::post('/topup-xendit', [BillingController::class, 'topUpXendit'])
            ->name('billing.topup-xendit');
    });

    // ==================== Billing Usage & Cost API ====================
    // Cost tracking and billing dashboard endpoints
    
    // Client billing summary
    Route::get('/summary', [\App\Http\Controllers\Api\BillingApiController::class, 'summary'])
        ->name('billing.summary');
    
    // Owner billing summary (all clients)
    Route::get('/owner/summary', [\App\Http\Controllers\Api\BillingApiController::class, 'ownerSummary'])
        ->name('billing.owner-summary');
    
    // Cost limits management
    Route::get('/limits', [\App\Http\Controllers\Api\BillingApiController::class, 'getLimits'])
        ->name('billing.limits.get');
    Route::post('/limits', [\App\Http\Controllers\Api\BillingApiController::class, 'updateLimits'])
        ->name('billing.limits.update');
    Route::post('/limits/unblock', [\App\Http\Controllers\Api\BillingApiController::class, 'unblock'])
        ->name('billing.limits.unblock');
    
    // Meta costs (owner only)
    Route::get('/meta-costs', [\App\Http\Controllers\Api\BillingApiController::class, 'getMetaCosts'])
        ->name('billing.meta-costs');
    
    // Reports
    Route::get('/monthly-report', [\App\Http\Controllers\Api\BillingApiController::class, 'monthlyReport'])
        ->name('billing.monthly-report');
    Route::get('/invoiceable', [\App\Http\Controllers\Api\BillingApiController::class, 'getInvoiceable'])
        ->name('billing.invoiceable');
    
    // Aggregation (admin only - for manual trigger)
    Route::post('/aggregate', [\App\Http\Controllers\Api\BillingApiController::class, 'aggregate'])
        ->name('billing.aggregate');
});

/*
|--------------------------------------------------------------------------
| Reporting & KPI Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk reporting dan KPI SaaS.
| 
| OWNER: Visibilitas penuh untuk keputusan bisnis
| CLIENT: Hanya lihat usage & invoice miliknya
| READ-ONLY: Tidak mengubah data transaksi
|
*/

// Owner Reporting (requires owner/admin access)
Route::middleware('auth:sanctum')->prefix('reporting/owner')->group(function () {
    // Executive summary
    Route::get('/summary', [\App\Http\Controllers\Api\OwnerReportingController::class, 'summary'])
        ->name('reporting.owner.summary');
    
    // Trend data
    Route::get('/trend/daily', [\App\Http\Controllers\Api\OwnerReportingController::class, 'trendDaily'])
        ->name('reporting.owner.trend-daily');
    Route::get('/trend/monthly', [\App\Http\Controllers\Api\OwnerReportingController::class, 'trendMonthly'])
        ->name('reporting.owner.trend-monthly');
    
    // Risk radar
    Route::get('/risks', [\App\Http\Controllers\Api\OwnerReportingController::class, 'risks'])
        ->name('reporting.owner.risks');
    
    // KPI for specific period
    Route::get('/kpi/{period}', [\App\Http\Controllers\Api\OwnerReportingController::class, 'kpi'])
        ->name('reporting.owner.kpi');
    
    // Client reports
    Route::get('/clients', [\App\Http\Controllers\Api\OwnerReportingController::class, 'clients'])
        ->name('reporting.owner.clients');
    Route::get('/clients/at-risk', [\App\Http\Controllers\Api\OwnerReportingController::class, 'atRiskClients'])
        ->name('reporting.owner.clients-at-risk');
    Route::get('/clients/{klienId}', [\App\Http\Controllers\Api\OwnerReportingController::class, 'clientDetail'])
        ->name('reporting.owner.client-detail');
    
    // Manual recalculation
    Route::post('/recalculate', [\App\Http\Controllers\Api\OwnerReportingController::class, 'recalculate'])
        ->name('reporting.owner.recalculate');
});

// Client Reporting (self-service, own data only)
Route::middleware('auth:sanctum')->prefix('reporting/my')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\ClientReportingController::class, 'dashboard'])
        ->name('reporting.my.dashboard');
    
    // Summary
    Route::get('/summary', [\App\Http\Controllers\Api\ClientReportingController::class, 'summary'])
        ->name('reporting.my.summary');
    
    // Usage history
    Route::get('/usage', [\App\Http\Controllers\Api\ClientReportingController::class, 'usage'])
        ->name('reporting.my.usage');
    
    // Usage by category
    Route::get('/usage-by-category', [\App\Http\Controllers\Api\ClientReportingController::class, 'usageByCategory'])
        ->name('reporting.my.usage-by-category');
    
    // Invoices
    Route::get('/invoices', [\App\Http\Controllers\Api\ClientReportingController::class, 'invoices'])
        ->name('reporting.my.invoices');
});

/*
|--------------------------------------------------------------------------
| Reporting & Reconciliation Routes
|--------------------------------------------------------------------------
|
| KONSEP MUTLAK: Ledger adalah sumber kebenaran saldo.
| REKONSILIASI: Invoice PAID ↔ ledger credit, Message SUCCESS ↔ ledger debit
| AUDIT-READY: Semua laporan immutable dengan anomaly detection
|
| OWNER: Full access untuk audit dan reconciliation management
| CLIENT: Read-only access untuk laporan mereka sendiri
|
*/

// ===================== RECONCILIATION MANAGEMENT (Admin/Owner Only) =====================
Route::middleware(['auth:sanctum', 'role:owner|admin'])->prefix('reporting/reconciliation')->group(function () {
    
    // Manual Reconciliation Triggers
    Route::post('/daily/{date}', [App\Http\Controllers\Api\ReportingController::class, 'triggerDailyReconciliation'])
        ->name('reconciliation.trigger-daily');
    Route::post('/monthly/{year}/{month}', [App\Http\Controllers\Api\ReportingController::class, 'triggerMonthlyReconciliation'])
        ->name('reconciliation.trigger-monthly');
    
    // Reconciliation Reports
    Route::get('/reports', [App\Http\Controllers\Api\ReportingController::class, 'getReconciliationReports'])
        ->name('reconciliation.reports.index');
    Route::get('/reports/{reportId}', [App\Http\Controllers\Api\ReportingController::class, 'getReconciliationReport'])
        ->name('reconciliation.reports.show');
    
    // Anomaly Management
    Route::get('/anomalies', [App\Http\Controllers\Api\ReportingController::class, 'getAnomalies'])
        ->name('reconciliation.anomalies.index');
    Route::get('/anomalies/critical', [App\Http\Controllers\Api\ReportingController::class, 'getCriticalAnomalies'])
        ->name('reconciliation.anomalies.critical');
    Route::post('/anomalies/{anomalyId}/resolve', [App\Http\Controllers\Api\ReportingController::class, 'resolveAnomaly'])
        ->name('reconciliation.anomalies.resolve');
    Route::post('/anomalies/{anomalyId}/ignore', [App\Http\Controllers\Api\ReportingController::class, 'ignoreAnomaly'])
        ->name('reconciliation.anomalies.ignore');
    
    // Reconciliation Status & Health
    Route::get('/status', [App\Http\Controllers\Api\ReportingController::class, 'getReconciliationStatus'])
        ->name('reconciliation.status');
    Route::get('/health-check', [App\Http\Controllers\Api\ReportingController::class, 'performHealthCheck'])
        ->name('reconciliation.health-check');
});

// ===================== BALANCE REPORTING (Ledger-First) =====================
Route::middleware('auth:sanctum')->prefix('reporting/balance')->group(function () {
    
    // Owner: All users balance reports
    Route::middleware('role:owner|admin')->group(function () {
        Route::get('/all', [App\Http\Controllers\Api\ReportingController::class, 'getAllBalanceReports'])
            ->name('reporting.balance.all');
        Route::get('/users/{userId}', [App\Http\Controllers\Api\ReportingController::class, 'getUserBalanceReports'])
            ->name('reporting.balance.user');
    });
    
    // Client: Own balance reports only
    Route::get('/my', [App\Http\Controllers\Api\ReportingController::class, 'getMyBalanceReports'])
        ->name('reporting.balance.my');
    
    // General balance report endpoints
    Route::get('/daily', [App\Http\Controllers\Api\ReportingController::class, 'getDailyBalanceReports'])
        ->name('reporting.balance.daily');
    Route::get('/monthly', [App\Http\Controllers\Api\ReportingController::class, 'getMonthlyBalanceReports'])
        ->name('reporting.balance.monthly');
    Route::post('/generate/{date}', [App\Http\Controllers\Api\ReportingController::class, 'generateBalanceReport'])
        ->name('reporting.balance.generate')
        ->middleware('role:owner|admin');
});

// ===================== MESSAGE USAGE REPORTING =====================
Route::middleware('auth:sanctum')->prefix('reporting/message-usage')->group(function () {
    
    // Owner: All users message usage reports
    Route::middleware('role:owner|admin')->group(function () {
        Route::get('/all', [App\Http\Controllers\Api\ReportingController::class, 'getAllMessageUsageReports'])
            ->name('reporting.message-usage.all');
        Route::get('/users/{userId}', [App\Http\Controllers\Api\ReportingController::class, 'getUserMessageUsageReports'])
            ->name('reporting.message-usage.user');
        Route::get('/categories', [App\Http\Controllers\Api\ReportingController::class, 'getMessageUsageByCategory'])
            ->name('reporting.message-usage.categories');
    });
    
    // Client: Own message usage reports only
    Route::get('/my', [App\Http\Controllers\Api\ReportingController::class, 'getMyMessageUsageReports'])
        ->name('reporting.message-usage.my');
    Route::get('/my/categories', [App\Http\Controllers\Api\ReportingController::class, 'getMyMessageUsageByCategory'])
        ->name('reporting.message-usage.my.categories');
    
    // General message usage report endpoints
    Route::get('/daily', [App\Http\Controllers\Api\ReportingController::class, 'getDailyMessageUsageReports'])
        ->name('reporting.message-usage.daily');
    Route::get('/monthly', [App\Http\Controllers\Api\ReportingController::class, 'getMonthlyMessageUsageReports'])
        ->name('reporting.message-usage.monthly');
    Route::post('/generate/{date}', [App\Http\Controllers\Api\ReportingController::class, 'generateMessageUsageReport'])
        ->name('reporting.message-usage.generate')
        ->middleware('role:owner|admin');
});

// ===================== INVOICE REPORTING =====================
Route::middleware('auth:sanctum')->prefix('reporting/invoices')->group(function () {
    
    // Owner: All users invoice reports
    Route::middleware('role:owner|admin')->group(function () {
        Route::get('/all', [App\Http\Controllers\Api\ReportingController::class, 'getAllInvoiceReports'])
            ->name('reporting.invoices.all');
        Route::get('/users/{userId}', [App\Http\Controllers\Api\ReportingController::class, 'getUserInvoiceReports'])
            ->name('reporting.invoices.user');
        Route::get('/status/{status}', [App\Http\Controllers\Api\ReportingController::class, 'getInvoiceReportsByStatus'])
            ->name('reporting.invoices.status');
    });
    
    // Client: Own invoice reports only
    Route::get('/my', [App\Http\Controllers\Api\ReportingController::class, 'getMyInvoiceReports'])
        ->name('reporting.invoices.my');
    Route::get('/my/status/{status}', [App\Http\Controllers\Api\ReportingController::class, 'getMyInvoiceReportsByStatus'])
        ->name('reporting.invoices.my.status');
    
    // General invoice report endpoints
    Route::get('/daily', [App\Http\Controllers\Api\ReportingController::class, 'getDailyInvoiceReports'])
        ->name('reporting.invoices.daily');
    Route::get('/monthly', [App\Http\Controllers\Api\ReportingController::class, 'getMonthlyInvoiceReports'])
        ->name('reporting.invoices.monthly');
    Route::post('/generate/{date}', [App\Http\Controllers\Api\ReportingController::class, 'generateInvoiceReport'])
        ->name('reporting.invoices.generate')
        ->middleware('role:owner|admin');
});

// ===================== MONTHLY CLOSING & EXPORT MANAGEMENT =====================
Route::middleware(['auth:sanctum', 'role:owner|admin'])->prefix('owner/monthly-closings')->group(function () {
    
    // Monthly Closing Management
    Route::get('/', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'index'])
        ->name('monthly-closings.index');
    Route::get('/dashboard', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'dashboard'])
        ->name('monthly-closings.dashboard');
    Route::get('/{closing}', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'show'])
        ->name('monthly-closings.show');
    
    // Create & Process Closing
    Route::post('/', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'store'])
        ->name('monthly-closings.store');
    Route::post('/{closing}/retry', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'retry'])
        ->name('monthly-closings.retry');
    
    // Admin-only: Force Unlock (Dangerous Operation)
    Route::post('/{closing}/unlock', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'unlock'])
        ->name('monthly-closings.unlock')
        ->middleware('role:admin'); // Extra restriction for admin only
    
    // User Details & Analysis
    Route::get('/{closing}/users', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'users'])
        ->name('monthly-closings.users');
    
    // Export Management
    Route::get('/export-types', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'exportTypes'])
        ->name('monthly-closings.export-types');
    Route::post('/{closing}/export', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'export'])
        ->name('monthly-closings.export');
    Route::get('/exports/{filename}', [App\Http\Controllers\Api\Owner\MonthlyClosingController::class, 'downloadExport'])
        ->name('monthly-closings.download-export')
        ->where('filename', '[a-zA-Z0-9_\-\.]+'); // Secure filename pattern
});

// ===================== OWNER ADJUSTMENT WORKFLOW =====================
Route::middleware(['auth:sanctum', 'role:owner|admin'])->prefix('owner/adjustments')->group(function () {
    
    // Adjustment Management
    Route::get('/', [App\Http\Controllers\Api\AdjustmentController::class, 'index'])
        ->name('adjustments.index');
    Route::get('/statistics', [App\Http\Controllers\Api\AdjustmentController::class, 'statistics'])
        ->name('adjustments.statistics');
    Route::get('/{adjustment}', [App\Http\Controllers\Api\AdjustmentController::class, 'show'])
        ->name('adjustments.show');

    // Create & Validation
    Route::post('/validate', [App\Http\Controllers\Api\AdjustmentController::class, 'validateBeforeSubmit'])
        ->name('adjustments.validate');
    Route::post('/', [App\Http\Controllers\Api\AdjustmentController::class, 'store'])
        ->name('adjustments.store');

    // Approval Workflow
    Route::get('/pending-approvals', [App\Http\Controllers\Api\AdjustmentController::class, 'pendingApprovals'])
        ->name('adjustments.pending-approvals');
    Route::post('/{adjustment}/approve', [App\Http\Controllers\Api\AdjustmentController::class, 'approve'])
        ->name('adjustments.approve')
        ->middleware('role:owner|admin');
    Route::post('/{adjustment}/reject', [App\Http\Controllers\Api\AdjustmentController::class, 'reject'])
        ->name('adjustments.reject')
        ->middleware('role:owner|admin');
    Route::post('/bulk-approve', [App\Http\Controllers\Api\AdjustmentController::class, 'bulkApprove'])
        ->name('adjustments.bulk-approve')
        ->middleware('role:owner|admin');

    // User History
    Route::get('/users/{user}/history', [App\Http\Controllers\Api\AdjustmentController::class, 'userHistory'])
        ->name('adjustments.user-history');

    // Reference Data
    Route::get('/reason-codes', [App\Http\Controllers\Api\AdjustmentController::class, 'reasonCodes'])
        ->name('adjustments.reason-codes');
    Route::get('/categories', [App\Http\Controllers\Api\AdjustmentController::class, 'categories'])
        ->name('adjustments.categories');

    // File Downloads
    Route::get('/{adjustment}/attachment', [App\Http\Controllers\Api\AdjustmentController::class, 'downloadAttachment'])
        ->name('adjustments.download-attachment');
});

/*
|--------------------------------------------------------------------------
| Subscription Change Routes (Upgrade & Downgrade)
|--------------------------------------------------------------------------
|
| Endpoints untuk upgrade dan downgrade subscription.
| Upgrade = berlaku sekarang
| Downgrade = berlaku periode berikutnya
|
*/

Route::middleware(['auth:sanctum', 'ensure.client'])->prefix('subscription')->group(function () {
    // Get current subscription
    Route::get('/current', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'current'])
        ->name('subscription.current');
    
    // Get available plans
    Route::get('/plans', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'availablePlans'])
        ->name('subscription.plans');
    
    // Preview plan change (shows what will happen)
    Route::get('/preview', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'preview'])
        ->name('subscription.preview');
    
    // Change plan (upgrade or downgrade)
    Route::post('/change', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'change'])
        ->name('subscription.change');
    
    // Cancel pending downgrade
    Route::post('/cancel-pending', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'cancelPending'])
        ->name('subscription.cancel-pending');
    
    // Renew subscription
    Route::post('/renew', [\App\Http\Controllers\Api\SubscriptionChangeController::class, 'renew'])
        ->name('subscription.renew');
});

/*
|--------------------------------------------------------------------------
| Invoice & Payment Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk invoice management.
| IMPORTANT:
| - Status invoice TIDAK bisa diubah via API (hanya via webhook)
| - Semua perubahan di-log ke invoice_events
|
*/

Route::middleware('auth:sanctum')->prefix('invoices')->group(function () {
    // List invoices
    Route::get('/', [\App\Http\Controllers\Api\InvoiceController::class, 'index'])
        ->name('invoices.index');
    
    // Get invoice detail
    Route::get('/{id}', [\App\Http\Controllers\Api\InvoiceController::class, 'show'])
        ->name('invoices.show');
    
    // Check invoice/payment status
    Route::get('/{id}/status', [\App\Http\Controllers\Api\InvoiceController::class, 'status'])
        ->name('invoices.status');
    
    // Create payment link (get snap token)
    Route::post('/{id}/pay', [\App\Http\Controllers\Api\InvoiceController::class, 'pay'])
        ->name('invoices.pay');
    
    // Create new subscription invoice
    Route::post('/subscription', [\App\Http\Controllers\Api\InvoiceController::class, 'createSubscription'])
        ->name('invoices.subscription');
    
    // Create upgrade invoice
    Route::post('/upgrade', [\App\Http\Controllers\Api\InvoiceController::class, 'createUpgrade'])
        ->name('invoices.upgrade');
    
    // Create renewal invoice
    Route::post('/renewal', [\App\Http\Controllers\Api\InvoiceController::class, 'createRenewal'])
        ->name('invoices.renewal');
});

/*
|--------------------------------------------------------------------------
| Tax & E-Invoice Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk manajemen pajak dan e-Faktur.
| 
| CLIENT: Kelola tax profile sendiri
| OWNER: Tax settings, verifikasi, reporting
| 
| PENTING:
| - PPN 11% dihitung saat invoice dibuat
| - Invoice SSOT, tidak diubah setelah paid
| - Siap integrasi e-Faktur DJP
|
*/

Route::middleware('auth:sanctum')->prefix('tax')->group(function () {
    // ==================== Client Tax Profile ====================
    // Client kelola tax profile sendiri
    Route::get('/profile', [\App\Http\Controllers\Api\TaxController::class, 'getProfile'])
        ->name('tax.profile');
    Route::post('/profile', [\App\Http\Controllers\Api\TaxController::class, 'saveProfile'])
        ->name('tax.profile.save');
    
    // ==================== Tax Calculation ====================
    // Preview tax calculation
    Route::post('/preview', [\App\Http\Controllers\Api\TaxController::class, 'preview'])
        ->name('tax.preview');
    
    // Apply tax to invoice
    Route::post('/apply/{invoiceId}', [\App\Http\Controllers\Api\TaxController::class, 'applyToInvoice'])
        ->name('tax.apply');
    
    // ==================== Invoice PDF ====================
    Route::get('/invoice/{invoiceId}/pdf', [\App\Http\Controllers\Api\TaxController::class, 'getInvoicePdf'])
        ->name('tax.invoice.pdf');
    Route::post('/invoice/{invoiceId}/pdf/regenerate', [\App\Http\Controllers\Api\TaxController::class, 'regenerateInvoicePdf'])
        ->name('tax.invoice.pdf.regenerate');
    
    // ==================== Tax Summary (Owner) ====================
    Route::get('/summary', [\App\Http\Controllers\Api\TaxController::class, 'getSummary'])
        ->name('tax.summary');
    
    // ==================== Owner Only: Tax Settings ====================
    Route::get('/settings', [\App\Http\Controllers\Api\TaxController::class, 'getSettings'])
        ->name('tax.settings');
    Route::post('/settings', [\App\Http\Controllers\Api\TaxController::class, 'saveSettings'])
        ->name('tax.settings.save');
    
    // ==================== Owner Only: Client Profiles Management ====================
    Route::get('/profiles', [\App\Http\Controllers\Api\TaxController::class, 'listProfiles'])
        ->name('tax.profiles.list');
    Route::post('/profiles/{id}/verify', [\App\Http\Controllers\Api\TaxController::class, 'verifyProfile'])
        ->name('tax.profiles.verify');
    
    // ==================== E-Faktur Queue (Owner) ====================
    Route::get('/efaktur/queue', [\App\Http\Controllers\Api\TaxController::class, 'getEfakturQueue'])
        ->name('tax.efaktur.queue');
});

/*
|--------------------------------------------------------------------------
| SLA & Support Ticket Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk manajemen tiket support dan SLA.
| 
| OWNER: Full CRUD tiket, assign, transition, SLA config, reporting
| CLIENT: Create tiket, view own, reply, lihat SLA status
| 
| FEATURES:
| - SLA terikat paket subscription (snapshot)
| - Response & resolution time tracking
| - Business hours calculation
| - Breach alert ke Owner
| - Lifecycle: new → acknowledged → in_progress → resolved → closed
|
*/

// Support options (public - for forms)
Route::middleware('auth:sanctum')->get('/support/options', [\App\Http\Controllers\Api\SupportTicketController::class, 'options'])
    ->name('support.options');

// Owner Support Management
Route::middleware('auth:sanctum')->prefix('owner/support')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\SupportTicketController::class, 'dashboard'])
        ->name('owner.support.dashboard');
    
    // Tickets CRUD
    Route::get('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'index'])
        ->name('owner.support.tickets.index');
    Route::post('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'store'])
        ->name('owner.support.tickets.store');
    Route::get('/tickets/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'show'])
        ->name('owner.support.tickets.show');
    Route::put('/tickets/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'update'])
        ->name('owner.support.tickets.update');
    
    // Ticket actions
    Route::post('/tickets/{id}/transition', [\App\Http\Controllers\Api\SupportTicketController::class, 'transition'])
        ->name('owner.support.tickets.transition');
    Route::post('/tickets/{id}/assign', [\App\Http\Controllers\Api\SupportTicketController::class, 'assign'])
        ->name('owner.support.tickets.assign');
    Route::post('/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportTicketController::class, 'reply'])
        ->name('owner.support.tickets.reply');
});

// Owner SLA Management
Route::middleware('auth:sanctum')->prefix('owner/sla')->group(function () {
    // SLA Config CRUD
    Route::get('/configs', [\App\Http\Controllers\Api\SlaController::class, 'indexConfigs'])
        ->name('owner.sla.configs.index');
    Route::post('/configs', [\App\Http\Controllers\Api\SlaController::class, 'storeConfig'])
        ->name('owner.sla.configs.store');
    Route::put('/configs/{id}', [\App\Http\Controllers\Api\SlaController::class, 'updateConfig'])
        ->name('owner.sla.configs.update');
    Route::delete('/configs/{id}', [\App\Http\Controllers\Api\SlaController::class, 'destroyConfig'])
        ->name('owner.sla.configs.destroy');
    
    // SLA Reporting
    Route::get('/compliance', [\App\Http\Controllers\Api\SlaController::class, 'compliance'])
        ->name('owner.sla.compliance');
    Route::get('/summary', [\App\Http\Controllers\Api\SlaController::class, 'summary'])
        ->name('owner.sla.summary');
    Route::get('/average-times', [\App\Http\Controllers\Api\SlaController::class, 'averageTimes'])
        ->name('owner.sla.average-times');
    Route::get('/at-risk', [\App\Http\Controllers\Api\SlaController::class, 'atRisk'])
        ->name('owner.sla.at-risk');
    
    // Breach alerts
    Route::get('/breaches', [\App\Http\Controllers\Api\SlaController::class, 'breachHistory'])
        ->name('owner.sla.breaches');
    Route::get('/breaches/alerts', [\App\Http\Controllers\Api\SlaController::class, 'breachAlerts'])
        ->name('owner.sla.breaches.alerts');
    Route::post('/breaches/{id}/notify', [\App\Http\Controllers\Api\SlaController::class, 'markBreachNotified'])
        ->name('owner.sla.breaches.notify');
});

// Client Support (self-service)
Route::middleware('auth:sanctum')->prefix('client/support')->group(function () {
    // Tickets
    Route::get('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'clientIndex'])
        ->name('client.support.tickets.index');
    Route::post('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'clientStore'])
        ->name('client.support.tickets.store');
    Route::get('/tickets/{id}', [\App\Http\Controllers\Api\SupportTicketController::class, 'clientShow'])
        ->name('client.support.tickets.show');
    Route::post('/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportTicketController::class, 'clientReply'])
        ->name('client.support.tickets.reply');
    Route::get('/tickets/{id}/sla', [\App\Http\Controllers\Api\SupportTicketController::class, 'clientSlaStatus'])
        ->name('client.support.tickets.sla');
});

/*
|--------------------------------------------------------------------------
| Enhanced SLA-Aware Support Routes
|--------------------------------------------------------------------------
|
| New SLA-aware support system with strict compliance monitoring:
| - Package-based channel access control
| - Real-time SLA compliance tracking  
| - Automatic escalation on breach
| - Comprehensive audit trail
| - No hardcoded priorities or SLA bypass
|
*/

// SLA-Aware Support Tickets (for customers)
Route::middleware('auth:sanctum')->prefix('sla-support')->group(function () {
    // Core ticket operations
    Route::get('/tickets', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'index'])
        ->name('sla-support.tickets.index');
    Route::post('/tickets', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'store'])
        ->name('sla-support.tickets.store');
    Route::get('/tickets/{id}', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'show'])
        ->name('sla-support.tickets.show');
    Route::put('/tickets/{id}', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'update'])
        ->name('sla-support.tickets.update');
    Route::post('/tickets/{id}/close', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'close'])
        ->name('sla-support.tickets.close');
    Route::post('/tickets/{id}/reopen', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'reopen'])
        ->name('sla-support.tickets.reopen');
    
    // Communication
    Route::post('/tickets/{id}/responses', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'addResponse'])
        ->name('sla-support.tickets.addResponse');
    
    // SLA monitoring
    Route::get('/tickets/{id}/sla-status', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'getSlaStatus'])
        ->name('sla-support.tickets.slaStatus');
    
    // Escalation
    Route::post('/tickets/{id}/escalate', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'requestEscalation'])
        ->name('sla-support.tickets.escalate');
    
    // Channel information
    Route::get('/channels', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'getAvailableChannels'])
        ->name('sla-support.channels');
    Route::get('/package-info', [\App\Http\Controllers\Api\SlaAwareSupportController::class, 'getPackageInfo'])
        ->name('sla-support.packageInfo');
});

// SLA Dashboard & Analytics (for management and agents)
Route::middleware('auth:sanctum')->prefix('sla-dashboard')->group(function () {
    // Real-time compliance monitoring
    Route::get('/compliance/overview', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getComplianceOverview'])
        ->name('sla-dashboard.compliance.overview');
    Route::get('/compliance/alerts', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getLiveBreachAlerts'])
        ->name('sla-dashboard.compliance.alerts');
    Route::get('/metrics/realtime', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getRealTimeMetrics'])
        ->name('sla-dashboard.metrics.realtime');
    
    // Historical analysis
    Route::get('/performance/historical', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getHistoricalPerformance'])
        ->name('sla-dashboard.performance.historical');
    Route::get('/trends/compliance', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getComplianceTrends'])
        ->name('sla-dashboard.trends.compliance');
    Route::get('/benchmarks/comparison', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getBenchmarkComparison'])
        ->name('sla-dashboard.benchmarks.comparison');
    
    // Performance by category
    Route::get('/performance/packages', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getPackagePerformance'])
        ->name('sla-dashboard.performance.packages');
    Route::get('/performance/agents', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getAgentPerformance'])
        ->name('sla-dashboard.performance.agents');
    
    // Escalation analytics
    Route::get('/escalations/analytics', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getEscalationAnalytics'])
        ->name('sla-dashboard.escalations.analytics');
    
    // Reporting
    Route::post('/reports/export', [\App\Http\Controllers\Api\SlaDashboardController::class, 'exportPerformanceReport'])
        ->name('sla-dashboard.reports.export');
    
    // Configuration
    Route::get('/configuration', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getSlaConfiguration'])
        ->name('sla-dashboard.configuration');
    
    // Customer self-service (no role restriction)
    Route::get('/my/compliance', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getMyCompliance'])
        ->name('sla-dashboard.my.compliance')
        ->withoutMiddleware('role:admin,manager,agent');
    Route::get('/my/stats', [\App\Http\Controllers\Api\SlaDashboardController::class, 'getMyTicketStats'])
        ->name('sla-dashboard.my.stats')
        ->withoutMiddleware('role:admin,manager,agent');
});

/*
|--------------------------------------------------------------------------
| Refund & Dispute Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk refund dan dispute management.
| 
| OWNER: Full CRUD, approve/reject, process
| CLIENT: Submit, view own, cancel/provide info
| 
| PRINCIPLES:
| - Invoice tetap SSOT keuangan
| - Semua refund/dispute melalui approval Owner
| - Credit balance sebagai opsi utama
| - Full audit trail
|
*/

// Options (public - for forms)
Route::middleware('auth:sanctum')->get('/refunds/options', [\App\Http\Controllers\Api\RefundController::class, 'options'])
    ->name('refunds.options');
Route::middleware('auth:sanctum')->get('/disputes/options', [\App\Http\Controllers\Api\DisputeController::class, 'options'])
    ->name('disputes.options');

// Owner Refund Management
Route::middleware('auth:sanctum')->prefix('owner/refunds')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\RefundController::class, 'stats'])
        ->name('owner.refunds.stats');
    Route::get('/', [\App\Http\Controllers\Api\RefundController::class, 'index'])
        ->name('owner.refunds.index');
    Route::get('/{id}', [\App\Http\Controllers\Api\RefundController::class, 'show'])
        ->name('owner.refunds.show');
    Route::post('/{id}/review', [\App\Http\Controllers\Api\RefundController::class, 'startReview'])
        ->name('owner.refunds.review');
    Route::post('/{id}/approve', [\App\Http\Controllers\Api\RefundController::class, 'approve'])
        ->name('owner.refunds.approve');
    Route::post('/{id}/reject', [\App\Http\Controllers\Api\RefundController::class, 'reject'])
        ->name('owner.refunds.reject');
    Route::post('/{id}/process', [\App\Http\Controllers\Api\RefundController::class, 'process'])
        ->name('owner.refunds.process');
    Route::post('/{id}/note', [\App\Http\Controllers\Api\RefundController::class, 'addNote'])
        ->name('owner.refunds.note');
});

// Owner Dispute Management
Route::middleware('auth:sanctum')->prefix('owner/disputes')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\DisputeController::class, 'stats'])
        ->name('owner.disputes.stats');
    Route::get('/', [\App\Http\Controllers\Api\DisputeController::class, 'index'])
        ->name('owner.disputes.index');
    Route::get('/{id}', [\App\Http\Controllers\Api\DisputeController::class, 'show'])
        ->name('owner.disputes.show');
    Route::post('/{id}/acknowledge', [\App\Http\Controllers\Api\DisputeController::class, 'acknowledge'])
        ->name('owner.disputes.acknowledge');
    Route::post('/{id}/assign', [\App\Http\Controllers\Api\DisputeController::class, 'assign'])
        ->name('owner.disputes.assign');
    Route::post('/{id}/investigate', [\App\Http\Controllers\Api\DisputeController::class, 'investigate'])
        ->name('owner.disputes.investigate');
    Route::post('/{id}/request-info', [\App\Http\Controllers\Api\DisputeController::class, 'requestInfo'])
        ->name('owner.disputes.request-info');
    Route::post('/{id}/escalate', [\App\Http\Controllers\Api\DisputeController::class, 'escalate'])
        ->name('owner.disputes.escalate');
    Route::post('/{id}/resolve', [\App\Http\Controllers\Api\DisputeController::class, 'resolve'])
        ->name('owner.disputes.resolve');
    Route::post('/{id}/reject', [\App\Http\Controllers\Api\DisputeController::class, 'reject'])
        ->name('owner.disputes.reject');
    Route::post('/{id}/close', [\App\Http\Controllers\Api\DisputeController::class, 'close'])
        ->name('owner.disputes.close');
    Route::post('/{id}/note', [\App\Http\Controllers\Api\DisputeController::class, 'addNote'])
        ->name('owner.disputes.note');
});

// Client Refunds
Route::middleware('auth:sanctum')->prefix('client/refunds')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RefundController::class, 'clientIndex'])
        ->name('client.refunds.index');
    Route::post('/', [\App\Http\Controllers\Api\RefundController::class, 'clientStore'])
        ->name('client.refunds.store');
    Route::get('/{id}', [\App\Http\Controllers\Api\RefundController::class, 'clientShow'])
        ->name('client.refunds.show');
    Route::post('/{id}/cancel', [\App\Http\Controllers\Api\RefundController::class, 'clientCancel'])
        ->name('client.refunds.cancel');
});

// Client Disputes
Route::middleware('auth:sanctum')->prefix('client/disputes')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DisputeController::class, 'clientIndex'])
        ->name('client.disputes.index');
    Route::post('/', [\App\Http\Controllers\Api\DisputeController::class, 'clientStore'])
        ->name('client.disputes.store');
    Route::get('/{id}', [\App\Http\Controllers\Api\DisputeController::class, 'clientShow'])
        ->name('client.disputes.show');
    Route::post('/{id}/provide-info', [\App\Http\Controllers\Api\DisputeController::class, 'clientProvideInfo'])
        ->name('client.disputes.provide-info');
    Route::post('/{id}/comment', [\App\Http\Controllers\Api\DisputeController::class, 'clientComment'])
        ->name('client.disputes.comment');
});

/*
|--------------------------------------------------------------------------
| Legal & Terms Enforcement Routes
|--------------------------------------------------------------------------
|
| API untuk Legal Document versioning dan acceptance enforcement.
| - Owner: CRUD documents, activate/deactivate, view compliance stats
| - Client: View documents, accept, check compliance status
|
*/

// Owner Legal Document Management
Route::prefix('owner/legal')->middleware(['auth:sanctum', 'ensure.owner'])->group(function () {
    // Document types and options
    Route::get('/types', [\App\Http\Controllers\Api\LegalDocumentController::class, 'types'])
        ->name('owner.legal.types');
    
    // Overall compliance stats
    Route::get('/stats', [\App\Http\Controllers\Api\LegalDocumentController::class, 'stats'])
        ->name('owner.legal.stats');
    
    // Export acceptance report
    Route::get('/export', [\App\Http\Controllers\Api\LegalDocumentController::class, 'export'])
        ->name('owner.legal.export');
    
    // Version history by type
    Route::get('/types/{type}/versions', [\App\Http\Controllers\Api\LegalDocumentController::class, 'versionHistory'])
        ->name('owner.legal.versions');
    
    // Client status
    Route::get('/clients/{klienId}/status', [\App\Http\Controllers\Api\LegalDocumentController::class, 'clientStatus'])
        ->name('owner.legal.client-status');
    
    // Document CRUD
    Route::get('/documents', [\App\Http\Controllers\Api\LegalDocumentController::class, 'index'])
        ->name('owner.legal.index');
    Route::post('/documents', [\App\Http\Controllers\Api\LegalDocumentController::class, 'store'])
        ->name('owner.legal.store');
    Route::get('/documents/{id}', [\App\Http\Controllers\Api\LegalDocumentController::class, 'show'])
        ->name('owner.legal.show');
    Route::put('/documents/{id}', [\App\Http\Controllers\Api\LegalDocumentController::class, 'update'])
        ->name('owner.legal.update');
    Route::delete('/documents/{id}', [\App\Http\Controllers\Api\LegalDocumentController::class, 'destroy'])
        ->name('owner.legal.destroy');
    
    // Document actions
    Route::post('/documents/{id}/activate', [\App\Http\Controllers\Api\LegalDocumentController::class, 'activate'])
        ->name('owner.legal.activate');
    Route::post('/documents/{id}/deactivate', [\App\Http\Controllers\Api\LegalDocumentController::class, 'deactivate'])
        ->name('owner.legal.deactivate');
    
    // Document stats and pending
    Route::get('/documents/{id}/stats', [\App\Http\Controllers\Api\LegalDocumentController::class, 'documentStats'])
        ->name('owner.legal.document-stats');
    Route::get('/documents/{id}/pending', [\App\Http\Controllers\Api\LegalDocumentController::class, 'pendingClients'])
        ->name('owner.legal.pending');
    Route::get('/documents/{id}/events', [\App\Http\Controllers\Api\LegalDocumentController::class, 'documentEvents'])
        ->name('owner.legal.events');
});

// Client Legal Acceptance
Route::prefix('client/legal')->middleware(['auth:sanctum'])->group(function () {
    // Compliance status
    Route::get('/status', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'status'])
        ->name('client.legal.status');
    Route::get('/check', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'check'])
        ->name('client.legal.check');
    
    // Pending documents
    Route::get('/pending', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'pending'])
        ->name('client.legal.pending');
    
    // Active documents
    Route::get('/documents', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'activeDocuments'])
        ->name('client.legal.documents');
    Route::get('/documents/{id}', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'showDocument'])
        ->name('client.legal.documents.show');
    
    // Acceptance actions
    Route::post('/accept', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'accept'])
        ->name('client.legal.accept');
    Route::post('/accept-all', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'acceptAll'])
        ->name('client.legal.accept-all');
    
    // History
    Route::get('/history', [\App\Http\Controllers\Api\LegalAcceptanceController::class, 'history'])
        ->name('client.legal.history');
});

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Endpoint untuk menerima webhook dari WhatsApp provider.
| Routes ini TIDAK menggunakan CSRF protection.
|
*/

Route::prefix('webhook')->group(function () {
    // ==================== WABA Webhook (Delivery Reports) ====================
    // Primary endpoint untuk delivery reports dari WABA/BSP
    // Supports: Gupshup, Meta Cloud API, Twilio
    Route::post('/waba', [App\Http\Controllers\Api\WABAWebhookController::class, 'handle'])
        ->name('webhook.waba');
    Route::get('/waba', [App\Http\Controllers\Api\WABAWebhookController::class, 'verify'])
        ->name('webhook.waba.verify');
    Route::get('/waba/health', [App\Http\Controllers\Api\WABAWebhookController::class, 'health'])
        ->name('webhook.waba.health');

    // WhatsApp Webhook (Gupshup) - Legacy
    Route::post('/whatsapp', [WebhookController::class, 'handle'])
        ->name('webhook.whatsapp');
    Route::get('/whatsapp', [WebhookController::class, 'healthCheck'])
        ->name('webhook.whatsapp.health');
    
    // Template Status Webhook (Gupshup/Meta)
    Route::post('/whatsapp/template-status', [WebhookController::class, 'handleTemplateStatus'])
        ->name('webhook.whatsapp.template-status');
    
    // Legacy route for backward compatibility
    Route::post('/gupshup', [WebhookController::class, 'handle'])
        ->name('webhook.gupshup');
    Route::get('/gupshup', [WebhookController::class, 'healthCheck'])
        ->name('webhook.gupshup.verify');

    // ==================== Recipient Complaint Webhooks ====================
    // Endpoints untuk menerima laporan spam/abuse dari recipients
    // Provider webhooks: Gupshup, Twilio, dll
    // NO AUTH - webhooks dari provider eksternal
    Route::prefix('complaints')->group(function () {
        // Gupshup complaint webhook (with signature validation)
        Route::post('/gupshup', [App\Http\Controllers\RecipientComplaintWebhookController::class, 'gupshupComplaint'])
            ->name('webhook.complaints.gupshup');
        
        // Twilio complaint webhook
        Route::post('/twilio', [App\Http\Controllers\RecipientComplaintWebhookController::class, 'twilioComplaint'])
            ->name('webhook.complaints.twilio');
        
        // Generic complaint API (untuk manual report atau third-party integration)
        Route::post('/generic', [App\Http\Controllers\RecipientComplaintWebhookController::class, 'genericComplaint'])
            ->name('webhook.complaints.generic');
        
        // Test endpoint (development only)
        Route::post('/test', [App\Http\Controllers\RecipientComplaintWebhookController::class, 'testComplaint'])
            ->name('webhook.complaints.test');
    });
});

/*
|--------------------------------------------------------------------------
| Gupshup WhatsApp Number Status Webhooks (Secured)
|--------------------------------------------------------------------------
|
| Endpoint untuk menerima update status nomor WhatsApp dari Gupshup:
| - whatsapp.number.approved
| - whatsapp.number.live  
| - whatsapp.number.activated
| - whatsapp.number.rejected
|
| Security:
| - X-Gupshup-Signature HMAC validation
| - IP whitelist validation
| - Idempotency check
|
*/

Route::prefix('webhooks/gupshup')->group(function () {
    // POST /api/webhooks/gupshup/whatsapp
    Route::post('/whatsapp', [GupshupWhatsAppNumberController::class, 'handle'])
        ->middleware('webhook.gupshup')
        ->name('api.webhooks.gupshup.whatsapp');
});

/*
|--------------------------------------------------------------------------
| WhatsApp Connection Status API (for UI polling)
|--------------------------------------------------------------------------
|
| Endpoint untuk UI polling status koneksi WhatsApp.
| Auto-update badge status tanpa refresh manual.
|
*/

Route::middleware('auth:sanctum')->prefix('whatsapp/connection')->group(function () {
    // GET /api/whatsapp/connection/status - Current user's connection status
    Route::get('/status', [App\Http\Controllers\Api\WhatsAppConnectionStatusController::class, 'show'])
        ->name('api.whatsapp.connection.status');
    
    // GET /api/whatsapp/connection/{klienId}/status - Specific klien (owner/admin only)
    Route::get('/{klienId}/status', [App\Http\Controllers\Api\WhatsAppConnectionStatusController::class, 'showByKlien'])
        ->name('api.whatsapp.connection.status.klien');
});

/*
|--------------------------------------------------------------------------
| WhatsApp Gateway Webhook (Node.js Gateway -> Laravel)
|--------------------------------------------------------------------------
|
| Endpoint untuk menerima event dari WhatsApp Gateway (Node.js).
| Events: connection.update, authenticated, disconnected, message.received
| TIDAK menggunakan auth middleware (dipanggil oleh Gateway dengan secret)
|
*/

Route::post('/whatsapp/webhook', [App\Http\Controllers\Api\WhatsAppWebhookController::class, 'handle'])
    ->name('api.whatsapp.webhook');

/*
|--------------------------------------------------------------------------
| Abuse Detection & Restriction API Routes
|--------------------------------------------------------------------------
|
| API untuk abuse detection, user restrictions, dan admin actions.
| Monitoring, management, dan enforcement.
|
*/

Route::middleware('auth:sanctum')->prefix('abuse')->group(function () {
    // Dashboard
    Route::get('/overview', [App\Http\Controllers\AbuseController::class, 'overview'])
        ->name('abuse.overview');
    
    // User Status
    Route::get('/status', [App\Http\Controllers\AbuseController::class, 'status'])
        ->name('abuse.status');
    Route::get('/history/{klienId}', [App\Http\Controllers\AbuseController::class, 'history'])
        ->name('abuse.history');
    
    // Restricted Users
    Route::get('/restricted', [App\Http\Controllers\AbuseController::class, 'restricted'])
        ->name('abuse.restricted');
    
    // Events
    Route::get('/events', [App\Http\Controllers\AbuseController::class, 'events'])
        ->name('abuse.events');
    Route::post('/events/{id}/review', [App\Http\Controllers\AbuseController::class, 'reviewEvent'])
        ->name('abuse.events.review');
    
    // Admin Actions
    Route::post('/warn', [App\Http\Controllers\AbuseController::class, 'warn'])
        ->name('abuse.warn');
    Route::post('/throttle', [App\Http\Controllers\AbuseController::class, 'throttle'])
        ->name('abuse.throttle');
    Route::post('/pause', [App\Http\Controllers\AbuseController::class, 'pause'])
        ->name('abuse.pause');
    Route::post('/suspend', [App\Http\Controllers\AbuseController::class, 'suspend'])
        ->name('abuse.suspend');
    Route::post('/lift', [App\Http\Controllers\AbuseController::class, 'lift'])
        ->name('abuse.lift');
    Route::post('/whitelist', [App\Http\Controllers\AbuseController::class, 'whitelist'])
        ->name('abuse.whitelist');
    Route::post('/blacklist', [App\Http\Controllers\AbuseController::class, 'blacklist'])
        ->name('abuse.blacklist');
    Route::post('/clear-override', [App\Http\Controllers\AbuseController::class, 'clearOverride'])
        ->name('abuse.clear-override');
    
    // Evaluate
    Route::post('/evaluate', [App\Http\Controllers\AbuseController::class, 'evaluate'])
        ->name('abuse.evaluate');
    
    // Rules
    Route::get('/rules', [App\Http\Controllers\AbuseController::class, 'rules'])
        ->name('abuse.rules');
    Route::put('/rules/{id}', [App\Http\Controllers\AbuseController::class, 'updateRule'])
        ->name('abuse.rules.update');
    
    // Quick Check API
    Route::get('/can-send/{klienId}', [App\Http\Controllers\AbuseController::class, 'canSend'])
        ->name('abuse.can-send');
});

/*
|--------------------------------------------------------------------------
| Inbox API Routes
|--------------------------------------------------------------------------
|
| API untuk mengelola inbox percakapan WhatsApp.
| Semua route membutuhkan autentikasi via Sanctum.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active'])->prefix('inbox')->group(function () {
    // Daftar & detail percakapan
    Route::get('/', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('/counter', [InboxController::class, 'counter'])->name('inbox.counter');
    Route::get('/{percakapanId}', [InboxController::class, 'show'])->name('inbox.show');

    // Aksi pada percakapan (non-billing actions)
    Route::post('/{percakapanId}/ambil', [InboxController::class, 'ambil'])->name('inbox.ambil');
    Route::post('/{percakapanId}/lepas', [InboxController::class, 'lepas'])->name('inbox.lepas');
    Route::post('/{percakapanId}/baca', [InboxController::class, 'tandaiBaca'])->name('inbox.baca');
    Route::post('/{percakapanId}/selesai', [InboxController::class, 'selesai'])->name('inbox.selesai');
    Route::post('/{percakapanId}/transfer', [InboxController::class, 'transfer'])->name('inbox.transfer');

    // Update prioritas
    Route::patch('/{percakapanId}/prioritas', [InboxController::class, 'updatePrioritas'])->name('inbox.prioritas');

    /*
    |----------------------------------------------------------------------
    | REVENUE GUARD 4-LAYER — Inbox Send Routes
    |----------------------------------------------------------------------
    | Layer 1: subscription.active (already inherited from parent group)
    | Layer 2: plan.limit:message (check message quota)
    | Layer 3: wallet.cost.guard:utility (check wallet balance)
    | Layer 4: Atomic deduction via RevenueGuardService (in Controller)
    |----------------------------------------------------------------------
    */
    Route::middleware(['plan.limit:message', 'wallet.cost.guard:utility'])->group(function () {
        Route::post('/{percakapanId}/kirim', [InboxController::class, 'kirimPesan'])->name('inbox.kirim');
        Route::post('/{percakapanId}/send-template', [InboxSendController::class, 'sendTemplate'])->name('inbox.send-template');
    });
});

/*
|--------------------------------------------------------------------------
| Template API Routes
|--------------------------------------------------------------------------
|
| API untuk mengelola template pesan WhatsApp (Meta compliant).
| Semua route membutuhkan autentikasi via Sanctum.
|
| ATURAN:
| - Sales: READ only
| - Admin & Owner: CRUD
| - Template diajukan/disetujui: READ ONLY
|
*/

Route::middleware(['auth:sanctum', 'subscription.active'])->prefix('templates')->group(function () {
    // Daftar template
    Route::get('/', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/disetujui', [TemplateController::class, 'disetujui'])->name('templates.disetujui');
    Route::get('/active', [InboxSendController::class, 'getActiveTemplates'])->name('templates.active');
    Route::post('/render-preview', [InboxSendController::class, 'renderPreview'])->name('templates.render-preview');
    
    // Sync status dari provider (admin/owner only)
    Route::post('/sync-status', [TemplateController::class, 'syncStatus'])->name('templates.sync-status');
    
    // CRUD template
    Route::post('/', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/{id}', [TemplateController::class, 'show'])->name('templates.show');
    Route::put('/{id}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('/{id}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    
    // Ajukan ke provider untuk review
    Route::post('/{id}/ajukan', [TemplateController::class, 'ajukan'])->name('templates.ajukan');
    
    // Arsipkan template
    Route::post('/{id}/arsip', [TemplateController::class, 'arsip'])->name('templates.arsip');
    
    // Extract variabel dari template
    Route::get('/{id}/variabel', [TemplateController::class, 'variabel'])->name('templates.variabel');
});

/*
|--------------------------------------------------------------------------
| Throttle Monitor API Routes
|--------------------------------------------------------------------------
|
| API untuk monitoring rate limiting dan campaign throttling.
| Semua route membutuhkan autentikasi via Sanctum.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active', 'feature:broadcast'])->prefix('throttle')->group(function () {
    // User stats
    Route::get('/stats', [App\Http\Controllers\ThrottleMonitorController::class, 'getMyStats'])
        ->name('throttle.stats');
    
    // Sender status
    Route::get('/senders', [App\Http\Controllers\ThrottleMonitorController::class, 'getSenderStatus'])
        ->name('throttle.senders');
    
    // Bucket status
    Route::get('/buckets', [App\Http\Controllers\ThrottleMonitorController::class, 'getBucketStatus'])
        ->name('throttle.buckets');
    
    // Throttle events
    Route::get('/events', [App\Http\Controllers\ThrottleMonitorController::class, 'getThrottleEvents'])
        ->name('throttle.events');
    
    // Campaign control
    Route::get('/campaign/{id}/progress', [App\Http\Controllers\ThrottleMonitorController::class, 'getCampaignProgress'])
        ->name('throttle.campaign.progress');
    Route::post('/campaign/{id}/pause', [App\Http\Controllers\ThrottleMonitorController::class, 'pauseCampaign'])
        ->name('throttle.campaign.pause');
    // Resume dispatches messages — needs full Revenue Guard stack
    Route::post('/campaign/{id}/resume', [App\Http\Controllers\ThrottleMonitorController::class, 'resumeCampaign'])
        ->name('throttle.campaign.resume')
        ->middleware(['plan.limit:campaign', 'wallet.cost.guard:campaign']);
    Route::post('/campaign/{id}/stop', [App\Http\Controllers\ThrottleMonitorController::class, 'stopCampaign'])
        ->name('throttle.campaign.stop');
});

// Admin-only throttle routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin/throttle')->group(function () {
    Route::get('/system', [App\Http\Controllers\ThrottleMonitorController::class, 'getSystemStats'])
        ->name('admin.throttle.system');
    Route::post('/force-limit', [App\Http\Controllers\ThrottleMonitorController::class, 'forceLimit'])
        ->name('admin.throttle.force-limit');
    Route::post('/clear-limit', [App\Http\Controllers\ThrottleMonitorController::class, 'clearLimit'])
        ->name('admin.throttle.clear-limit');
});

/*
|--------------------------------------------------------------------------
| Risk Scoring API Routes
|--------------------------------------------------------------------------
|
| API untuk anti-ban risk scoring system.
| Monitoring, management, dan enforcement actions.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active', 'feature:analytics'])->prefix('risk')->group(function () {
    // Dashboard & Summary
    Route::get('/summary', [App\Http\Controllers\RiskScoreController::class, 'summary'])
        ->name('risk.summary');
    
    // Risk Scores
    Route::get('/scores', [App\Http\Controllers\RiskScoreController::class, 'scores'])
        ->name('risk.scores');
    Route::get('/scores/{entityType}/{entityId}', [App\Http\Controllers\RiskScoreController::class, 'show'])
        ->name('risk.scores.show');
    
    // Evaluate
    Route::post('/evaluate', [App\Http\Controllers\RiskScoreController::class, 'evaluate'])
        ->name('risk.evaluate');
    Route::post('/evaluate-batch', [App\Http\Controllers\RiskScoreController::class, 'evaluateBatch'])
        ->name('risk.evaluate-batch');
    
    // Actions
    Route::get('/actions', [App\Http\Controllers\RiskScoreController::class, 'actions'])
        ->name('risk.actions');
    Route::post('/actions/{id}/revoke', [App\Http\Controllers\RiskScoreController::class, 'revokeAction'])
        ->name('risk.actions.revoke');
    Route::post('/actions/apply', [App\Http\Controllers\RiskScoreController::class, 'applyAction'])
        ->name('risk.actions.apply');
    
    // Events
    Route::get('/events', [App\Http\Controllers\RiskScoreController::class, 'events'])
        ->name('risk.events');
    
    // Factors (Admin configurable)
    Route::get('/factors', [App\Http\Controllers\RiskScoreController::class, 'factors'])
        ->name('risk.factors');
    Route::put('/factors/{id}', [App\Http\Controllers\RiskScoreController::class, 'updateFactor'])
        ->name('risk.factors.update');
    
    // Quick check API (untuk message sending)
    Route::get('/can-send/{entityType}/{entityId}', [App\Http\Controllers\RiskScoreController::class, 'canSend'])
        ->name('risk.can-send');
});

/*
|--------------------------------------------------------------------------
| Delivery Report API Routes
|--------------------------------------------------------------------------
|
| API untuk delivery reports, audit trail, dan analytics.
| Penting untuk dispute handling dan SLA tracking.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active', 'feature:analytics'])->prefix('delivery-reports')->group(function () {
    // Statistics
    Route::get('/stats', [App\Http\Controllers\Api\DeliveryReportController::class, 'getStatistics'])
        ->name('delivery-reports.stats');
    Route::get('/stats/hourly', [App\Http\Controllers\Api\DeliveryReportController::class, 'getHourlyStats'])
        ->name('delivery-reports.stats.hourly');
    Route::get('/sla', [App\Http\Controllers\Api\DeliveryReportController::class, 'getSlaMetrics'])
        ->name('delivery-reports.sla');
    
    // Events
    Route::get('/events', [App\Http\Controllers\Api\DeliveryReportController::class, 'getRecentEvents'])
        ->name('delivery-reports.events');
    Route::get('/message/{messageId}/events', [App\Http\Controllers\Api\DeliveryReportController::class, 'getMessageEvents'])
        ->name('delivery-reports.message.events');
    
    // Audit (for dispute)
    Route::get('/message/{messageId}/audit', [App\Http\Controllers\Api\DeliveryReportController::class, 'getAuditTrail'])
        ->name('delivery-reports.message.audit');
    
    // Export
    Route::get('/export', [App\Http\Controllers\Api\DeliveryReportController::class, 'exportReport'])
        ->name('delivery-reports.export');
});

/*
|--------------------------------------------------------------------------
| Compliance & Audit Log Routes
|--------------------------------------------------------------------------
|
| API untuk compliance logging, audit trail, dan legal retention.
| CRITICAL: Semua access di-log untuk audit trail.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active', 'feature:analytics'])->prefix('compliance')->group(function () {
    // ==================== Audit Logs ====================
    Route::get('/audit-logs', [App\Http\Controllers\ComplianceController::class, 'listAuditLogs'])
        ->name('compliance.audit-logs');
    Route::get('/audit-logs/{id}', [App\Http\Controllers\ComplianceController::class, 'getAuditLog'])
        ->name('compliance.audit-log.show');
    Route::get('/audit-logs/correlation/{correlationId}', [App\Http\Controllers\ComplianceController::class, 'getByCorrelation'])
        ->name('compliance.audit-log.correlation');
    
    // ==================== Admin Action Logs ====================
    Route::get('/admin-actions', [App\Http\Controllers\ComplianceController::class, 'listAdminActions'])
        ->name('compliance.admin-actions');
    Route::get('/admin-actions/sensitive', [App\Http\Controllers\ComplianceController::class, 'getSensitiveActions'])
        ->name('compliance.admin-actions.sensitive');
    
    // ==================== Access Logs ====================
    Route::get('/access-logs', [App\Http\Controllers\ComplianceController::class, 'listAccessLogs'])
        ->name('compliance.access-logs');
    Route::get('/access-logs/pii', [App\Http\Controllers\ComplianceController::class, 'getPiiAccessLogs'])
        ->name('compliance.access-logs.pii');
    
    // ==================== Config Change Logs ====================
    Route::get('/config-changes', [App\Http\Controllers\ComplianceController::class, 'listConfigChanges'])
        ->name('compliance.config-changes');
    
    // ==================== Legal Archives ====================
    Route::get('/archives', [App\Http\Controllers\ComplianceController::class, 'listArchives'])
        ->name('compliance.archives');
    Route::post('/archives/{id}/retrieve', [App\Http\Controllers\ComplianceController::class, 'retrieveArchive'])
        ->name('compliance.archives.retrieve');
    Route::get('/archives/{id}/integrity', [App\Http\Controllers\ComplianceController::class, 'verifyArchiveIntegrity'])
        ->name('compliance.archives.integrity');
    
    // ==================== Retention Policies ====================
    Route::get('/policies', [App\Http\Controllers\ComplianceController::class, 'listPolicies'])
        ->name('compliance.policies');
    Route::get('/policies/{id}', [App\Http\Controllers\ComplianceController::class, 'getPolicy'])
        ->name('compliance.policies.show');
    Route::put('/policies/{id}', [App\Http\Controllers\ComplianceController::class, 'updatePolicy'])
        ->name('compliance.policies.update');
    
    // ==================== Operations ====================
    Route::post('/archive/trigger', [App\Http\Controllers\ComplianceController::class, 'triggerArchive'])
        ->name('compliance.archive.trigger');
    Route::post('/purge/trigger', [App\Http\Controllers\ComplianceController::class, 'triggerPurge'])
        ->name('compliance.purge.trigger');
    Route::post('/integrity-check/trigger', [App\Http\Controllers\ComplianceController::class, 'triggerIntegrityCheck'])
        ->name('compliance.integrity.trigger');
    
    // ==================== Statistics ====================
    Route::get('/statistics', [App\Http\Controllers\ComplianceController::class, 'getStatistics'])
        ->name('compliance.statistics');
    Route::get('/klien/{klienId}/retention-summary', [App\Http\Controllers\ComplianceController::class, 'getKlienRetentionSummary'])
        ->name('compliance.klien.retention');
});

/*
|--------------------------------------------------------------------------
| Incident Response & Postmortem Routes
|--------------------------------------------------------------------------
|
| Endpoints untuk incident management, alert handling, on-call,
| dan postmortem documentation.
|
| FITUR:
| - Incident CRUD & lifecycle management
| - Alert rules & evaluation
| - On-call scheduling & escalation
| - Postmortem generation & validation
|
*/

Route::middleware(['auth:sanctum'])->prefix('incidents')->group(function () {
    // ==================== Dashboard ====================
    Route::get('/dashboard', [App\Http\Controllers\IncidentController::class, 'dashboard'])
        ->name('incidents.dashboard');
    Route::get('/statistics', [App\Http\Controllers\IncidentController::class, 'statistics'])
        ->name('incidents.statistics');

    // ==================== Incidents CRUD ====================
    Route::get('/', [App\Http\Controllers\IncidentController::class, 'index'])
        ->name('incidents.index');
    Route::post('/', [App\Http\Controllers\IncidentController::class, 'store'])
        ->name('incidents.store');
    Route::get('/{incident}', [App\Http\Controllers\IncidentController::class, 'show'])
        ->name('incidents.show');
    Route::put('/{incident}', [App\Http\Controllers\IncidentController::class, 'update'])
        ->name('incidents.update');

    // ==================== Status Transitions ====================
    Route::post('/{incident}/acknowledge', [App\Http\Controllers\IncidentController::class, 'acknowledge'])
        ->name('incidents.acknowledge');
    Route::post('/{incident}/investigate', [App\Http\Controllers\IncidentController::class, 'investigate'])
        ->name('incidents.investigate');
    Route::post('/{incident}/mitigate', [App\Http\Controllers\IncidentController::class, 'mitigate'])
        ->name('incidents.mitigate');
    Route::post('/{incident}/resolve', [App\Http\Controllers\IncidentController::class, 'resolve'])
        ->name('incidents.resolve');
    Route::post('/{incident}/close', [App\Http\Controllers\IncidentController::class, 'close'])
        ->name('incidents.close');

    // ==================== Timeline & Notes ====================
    Route::get('/{incident}/timeline', [App\Http\Controllers\IncidentController::class, 'timeline'])
        ->name('incidents.timeline');
    Route::post('/{incident}/notes', [App\Http\Controllers\IncidentController::class, 'addNote'])
        ->name('incidents.notes.add');
    Route::post('/{incident}/actions/log', [App\Http\Controllers\IncidentController::class, 'logAction'])
        ->name('incidents.actions.log');

    // ==================== Impact Assessment ====================
    Route::put('/{incident}/impact', [App\Http\Controllers\IncidentController::class, 'updateImpact'])
        ->name('incidents.impact.update');

    // ==================== Action Items (CAPA) ====================
    Route::get('/{incident}/action-items', [App\Http\Controllers\IncidentController::class, 'listActions'])
        ->name('incidents.action-items.list');
    Route::post('/{incident}/action-items', [App\Http\Controllers\IncidentController::class, 'createAction'])
        ->name('incidents.action-items.create');
    Route::put('/action-items/{action}', [App\Http\Controllers\IncidentController::class, 'updateAction'])
        ->name('incidents.action-items.update');

    // ==================== Postmortem ====================
    Route::get('/{incident}/postmortem', [App\Http\Controllers\IncidentController::class, 'getPostmortem'])
        ->name('incidents.postmortem.get');
    Route::get('/{incident}/postmortem/markdown', [App\Http\Controllers\IncidentController::class, 'getPostmortemMarkdown'])
        ->name('incidents.postmortem.markdown');
    Route::put('/{incident}/postmortem', [App\Http\Controllers\IncidentController::class, 'updatePostmortem'])
        ->name('incidents.postmortem.update');
    Route::post('/{incident}/postmortem/five-whys', [App\Http\Controllers\IncidentController::class, 'addFiveWhys'])
        ->name('incidents.postmortem.five-whys');
    Route::get('/{incident}/postmortem/validate', [App\Http\Controllers\IncidentController::class, 'validatePostmortem'])
        ->name('incidents.postmortem.validate');
});

Route::middleware(['auth:sanctum'])->prefix('alerts')->group(function () {
    // ==================== Alerts ====================
    Route::get('/', [App\Http\Controllers\IncidentController::class, 'listAlerts'])
        ->name('alerts.index');
    Route::post('/{alert}/acknowledge', [App\Http\Controllers\IncidentController::class, 'acknowledgeAlert'])
        ->name('alerts.acknowledge');
    Route::post('/{alert}/resolve', [App\Http\Controllers\IncidentController::class, 'resolveAlert'])
        ->name('alerts.resolve');
    Route::post('/{alert}/link', [App\Http\Controllers\IncidentController::class, 'linkAlertToIncident'])
        ->name('alerts.link');

    // ==================== Alert Rules ====================
    Route::get('/rules', [App\Http\Controllers\IncidentController::class, 'listAlertRules'])
        ->name('alerts.rules.index');
    Route::put('/rules/{alertRule}', [App\Http\Controllers\IncidentController::class, 'updateAlertRule'])
        ->name('alerts.rules.update');

    // ==================== Manual Trigger ====================
    Route::post('/evaluate', [App\Http\Controllers\IncidentController::class, 'triggerEvaluation'])
        ->name('alerts.evaluate');
});

Route::middleware(['auth:sanctum'])->prefix('oncall')->group(function () {
    // ==================== On-Call Schedule ====================
    Route::get('/schedule', [App\Http\Controllers\IncidentController::class, 'getOnCallSchedule'])
        ->name('oncall.schedule');
    Route::post('/schedule', [App\Http\Controllers\IncidentController::class, 'createOnCallSchedule'])
        ->name('oncall.schedule.create');
});

// ==============================================================================
// STATUS PAGE ROUTES
// ==============================================================================

// ==================== PUBLIC STATUS PAGE (No Auth) ====================
Route::prefix('status')->group(function () {
    // Main status page
    Route::get('/', [App\Http\Controllers\StatusPageController::class, 'index'])
        ->name('status.index');
    Route::get('/summary', [App\Http\Controllers\StatusPageController::class, 'summary'])
        ->name('status.summary');

    // Components
    Route::get('/components', [App\Http\Controllers\StatusPageController::class, 'components'])
        ->name('status.components');
    Route::get('/components/{slug}', [App\Http\Controllers\StatusPageController::class, 'component'])
        ->name('status.components.show');

    // Incidents
    Route::get('/incidents', [App\Http\Controllers\StatusPageController::class, 'incidents'])
        ->name('status.incidents');
    Route::get('/incidents/{id}', [App\Http\Controllers\StatusPageController::class, 'showIncident'])
        ->name('status.incidents.show');

    // Maintenance
    Route::get('/maintenance', [App\Http\Controllers\StatusPageController::class, 'maintenance'])
        ->name('status.maintenance');
    Route::get('/maintenance/{id}', [App\Http\Controllers\StatusPageController::class, 'showMaintenance'])
        ->name('status.maintenance.show');

    // Uptime
    Route::get('/uptime', [App\Http\Controllers\StatusPageController::class, 'uptime'])
        ->name('status.uptime');
});

// ==================== USER STATUS NOTIFICATIONS (Auth Required) ====================
Route::middleware(['auth:sanctum'])->prefix('user')->group(function () {
    // In-App Banners
    Route::get('/banners', [App\Http\Controllers\StatusPageController::class, 'userBanners'])
        ->name('user.banners');
    Route::post('/banners/{id}/dismiss', [App\Http\Controllers\StatusPageController::class, 'dismissBanner'])
        ->name('user.banners.dismiss');

    // Notification Subscriptions
    Route::get('/notifications/subscriptions', [App\Http\Controllers\StatusPageController::class, 'getSubscriptions'])
        ->name('user.notifications.subscriptions');
    Route::put('/notifications/subscriptions', [App\Http\Controllers\StatusPageController::class, 'updateSubscriptions'])
        ->name('user.notifications.subscriptions.update');

    // Notification History
    Route::get('/notifications', [App\Http\Controllers\StatusPageController::class, 'getNotifications'])
        ->name('user.notifications');
    Route::post('/notifications/{id}/read', [App\Http\Controllers\StatusPageController::class, 'markNotificationRead'])
        ->name('user.notifications.read');
});

// ==================== ADMIN STATUS PAGE (Auth + Admin) ====================
Route::middleware(['auth:sanctum'])->prefix('admin/status')->group(function () {
    // Dashboard
    Route::get('/dashboard', [App\Http\Controllers\StatusPageController::class, 'adminDashboard'])
        ->name('admin.status.dashboard');

    // Component Management
    Route::put('/components/{slug}', [App\Http\Controllers\StatusPageController::class, 'updateComponent'])
        ->name('admin.status.components.update');

    // Incident Management
    Route::post('/incidents', [App\Http\Controllers\StatusPageController::class, 'createIncident'])
        ->name('admin.status.incidents.create');
    Route::put('/incidents/{id}', [App\Http\Controllers\StatusPageController::class, 'updateIncident'])
        ->name('admin.status.incidents.update');
    Route::post('/incidents/{id}/publish', [App\Http\Controllers\StatusPageController::class, 'publishIncident'])
        ->name('admin.status.incidents.publish');
    Route::post('/incidents/{id}/update', [App\Http\Controllers\StatusPageController::class, 'postUpdate'])
        ->name('admin.status.incidents.postUpdate');
    Route::post('/incidents/{id}/resolve', [App\Http\Controllers\StatusPageController::class, 'resolveIncident'])
        ->name('admin.status.incidents.resolve');

    // Maintenance Management
    Route::post('/maintenance', [App\Http\Controllers\StatusPageController::class, 'createMaintenance'])
        ->name('admin.status.maintenance.create');
    Route::post('/maintenance/{id}/start', [App\Http\Controllers\StatusPageController::class, 'startMaintenance'])
        ->name('admin.status.maintenance.start');
    Route::post('/maintenance/{id}/complete', [App\Http\Controllers\StatusPageController::class, 'completeMaintenance'])
        ->name('admin.status.maintenance.complete');

    // Announcements
    Route::post('/announcement', [App\Http\Controllers\StatusPageController::class, 'sendAnnouncement'])
        ->name('admin.status.announcement');

    // Templates
    Route::get('/templates', [App\Http\Controllers\StatusPageController::class, 'getTemplates'])
        ->name('admin.status.templates');
});

// ==============================================================================
// TRUST METRICS ROUTES
// ==============================================================================

Route::middleware(['auth:sanctum'])->prefix('metrics/trust')->group(function () {
    // Summary
    Route::get('/summary', [App\Http\Controllers\TrustMetricsController::class, 'summary'])
        ->name('metrics.trust.summary');

    // Uptime
    Route::get('/uptime', [App\Http\Controllers\TrustMetricsController::class, 'uptime'])
        ->name('metrics.trust.uptime');

    // Incidents
    Route::get('/incidents', [App\Http\Controllers\TrustMetricsController::class, 'incidents'])
        ->name('metrics.trust.incidents');

    // Notifications
    Route::get('/notifications', [App\Http\Controllers\TrustMetricsController::class, 'notifications'])
        ->name('metrics.trust.notifications');

    // Response Times (MTTA, MTTR)
    Route::get('/response-times', [App\Http\Controllers\TrustMetricsController::class, 'responseTimes'])
        ->name('metrics.trust.response-times');

    // Component Uptime
    Route::get('/component-uptime', [App\Http\Controllers\TrustMetricsController::class, 'componentUptime'])
        ->name('metrics.trust.component-uptime');

    // Period Comparison
    Route::get('/comparison', [App\Http\Controllers\TrustMetricsController::class, 'comparison'])
        ->name('metrics.trust.comparison');
});

// ==============================================================================
// EXECUTIVE OWNER DASHBOARD ROUTES
// ==============================================================================
// Target: Owner/C-Level (non-teknis)
// Features: Health Score, Top Risks, Platform Status, Revenue at Risk
// Cache: 60-120 detik

Route::middleware(['auth:sanctum'])->prefix('executive')->group(function () {
    // Main Dashboard - Cached response
    Route::get('/dashboard', [App\Http\Controllers\Api\ExecutiveOwnerDashboardController::class, 'index'])
        ->name('executive.dashboard');

    // Force Refresh - Bypass cache
    Route::post('/dashboard/refresh', [App\Http\Controllers\Api\ExecutiveOwnerDashboardController::class, 'refresh'])
        ->name('executive.dashboard.refresh');

    // Quick Health Check - Lightweight
    Route::get('/health', [App\Http\Controllers\Api\ExecutiveOwnerDashboardController::class, 'health'])
        ->name('executive.health');
});

/*
|--------------------------------------------------------------------------
| Alert & Early Warning Routes
|--------------------------------------------------------------------------
|
| KONSEP MUTLAK:
| 1. Alert bersifat preventif, bukan pengganti Saldo Guard
| 2. User: View & acknowledge own alerts
| 3. Owner: Monitor semua alerts & trigger manual checks
| 4. Deduplicated dengan cooldown mechanism
|
*/

// ===================== USER-FACING ALERT ENDPOINTS =====================
Route::middleware('auth:sanctum')->prefix('alerts')->group(function () {
    
    // Get user's alerts
    Route::get('/my', [AlertController::class, 'getMyAlerts'])
        ->name('alerts.my');
    
    // Get unread count (for badge display)
    Route::get('/unread-count', [AlertController::class, 'getUnreadCount'])
        ->name('alerts.unread-count');
    
    // Acknowledge specific alert
    Route::post('/{alertId}/acknowledge', [AlertController::class, 'acknowledgeAlert'])
        ->name('alerts.acknowledge');
    
    // Mark all notifications as read
    Route::post('/mark-all-read', [AlertController::class, 'markAllAsRead'])
        ->name('alerts.mark-all-read');
});

// ===================== OWNER/ADMIN ALERT MANAGEMENT =====================
Route::middleware(['auth:sanctum', 'role:owner|admin'])->prefix('alerts/admin')->group(function () {
    
    // Get all alerts dengan filtering
    Route::get('/all', [AlertController::class, 'getAllAlerts'])
        ->name('alerts.admin.all');
    
    // Alert dashboard untuk owner
    Route::get('/dashboard', [AlertController::class, 'getDashboard'])
        ->name('alerts.admin.dashboard');
    
    // Manual trigger balance check
    Route::post('/trigger/balance-check', [AlertController::class, 'triggerBalanceCheck'])
        ->name('alerts.admin.trigger-balance-check');
    
    // Manual trigger cost anomaly detection
    Route::post('/trigger/cost-anomaly', [AlertController::class, 'triggerCostAnomalyCheck'])
        ->name('alerts.admin.trigger-cost-anomaly');
    
    // Get/update alert configuration
    Route::get('/config', [AlertController::class, 'getConfiguration'])
        ->name('alerts.admin.config');
});

/*
|--------------------------------------------------------------------------
| User Quota Routes
|--------------------------------------------------------------------------
|
| Endpoint untuk cek dan manage quota user berdasarkan plan.
| HARDCAP enforcement - tidak ada bypass.
|
*/

Route::middleware(['auth:sanctum', 'subscription.active'])->prefix('quota')->group(function () {
    // Get current quota info
    Route::get('/', [App\Http\Controllers\Api\QuotaController::class, 'show'])
        ->name('quota.show');
    
    // Check if can send messages
    Route::post('/check-send', [App\Http\Controllers\Api\QuotaController::class, 'checkSend'])
        ->name('quota.check-send');
    
    // Check if can create campaign
    Route::post('/check-campaign', [App\Http\Controllers\Api\QuotaController::class, 'checkCampaign'])
        ->name('quota.check-campaign');
});

/*
|--------------------------------------------------------------------------
| Tax Management & Invoice PDF Routes
|--------------------------------------------------------------------------
|
| CRITICAL: Tax & Invoice PDF system dengan immutability protection
| 
| Business Rules:
| - ❌ NO tax recalculation after invoice status = PAID
| - ✅ PDF generation ONLY for PAID invoices with tax snapshot
| - ✅ Complete audit trail for compliance
| - ✅ Indonesian tax compliance (PPN, PPh, PKP, NPWP)
|
*/

Route::middleware(['auth:sanctum'])->prefix('tax-management')->group(function () {
    
    // ==================== Tax Settings ====================
    Route::get('/settings', [App\Http\Controllers\Api\TaxManagementController::class, 'getTaxSettings'])
        ->name('tax.settings.get');
    
    Route::put('/settings', [App\Http\Controllers\Api\TaxManagementController::class, 'updateTaxSettings'])
        ->name('tax.settings.update');
    
    // ==================== Company Profile ====================
    Route::get('/company-profile', [App\Http\Controllers\Api\TaxManagementController::class, 'getCompanyProfile'])
        ->name('tax.company.get');
    
    Route::put('/company-profile', [App\Http\Controllers\Api\TaxManagementController::class, 'updateCompanyProfile'])
        ->name('tax.company.update');
    
    // ==================== Client Tax Configuration ====================
    Route::get('/client-configurations', [App\Http\Controllers\Api\TaxManagementController::class, 'getClientTaxConfigurations'])
        ->name('tax.clients.list');
    
    Route::put('/client-configuration', [App\Http\Controllers\Api\TaxManagementController::class, 'updateClientTaxConfiguration'])
        ->name('tax.clients.update');
    
    Route::delete('/client-configuration', [App\Http\Controllers\Api\TaxManagementController::class, 'deleteClientTaxConfiguration'])
        ->name('tax.clients.delete');
    
    // ==================== Tax Rules & Utilities ====================
    Route::get('/rules', [App\Http\Controllers\Api\TaxManagementController::class, 'getTaxRules'])
        ->name('tax.rules');
    
    Route::post('/calculate-preview', [App\Http\Controllers\Api\TaxManagementController::class, 'getDefaultTaxBreakdown'])
        ->name('tax.calculate.preview');
    
    Route::post('/validate-npwp', [App\Http\Controllers\Api\TaxManagementController::class, 'validateNpwp'])
        ->name('tax.validate.npwp');
    
    Route::get('/compliance-summary', [App\Http\Controllers\Api\TaxManagementController::class, 'getTaxComplianceSummary'])
        ->name('tax.compliance.summary');
});

/*
|--------------------------------------------------------------------------
| Invoice PDF & Tax Calculation Routes
|--------------------------------------------------------------------------
|
| IMMUTABILITY PROTECTED: Invoice operations dengan strict business rules
| 
| Critical Security:
| - Tax calculation only before payment
| - PDF generation only after payment
| - Complete integrity validation
|
*/

Route::middleware(['auth:sanctum'])->prefix('invoices')->group(function () {
    
    // ==================== Tax Calculation (Pre-Payment Only) ====================
    Route::post('/{invoice}/calculate-tax', [App\Http\Controllers\Api\InvoicePdfController::class, 'calculateTax'])
        ->name('invoices.tax.calculate');
    
    Route::post('/{invoice}/mark-paid', [App\Http\Controllers\Api\InvoicePdfController::class, 'markPaidAndLock'])
        ->name('invoices.mark-paid');
    
    // ==================== PDF Operations (Post-Payment Only) ====================
    Route::post('/{invoice}/generate-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'generatePdf'])
        ->name('invoices.pdf.generate');
    
    Route::get('/{invoice}/download-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'downloadPdf'])
        ->name('invoices.pdf.download');
    
    Route::get('/{invoice}/view-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'viewPdf'])
        ->name('invoices.pdf.view');
    
    Route::get('/{invoice}/pdf-url', [App\Http\Controllers\Api\InvoicePdfController::class, 'getPdfUrl'])
        ->name('invoices.pdf.url');
    
    Route::post('/{invoice}/regenerate-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'regeneratePdf'])
        ->name('invoices.pdf.regenerate');
    
    // ==================== Validation & Integrity Checks ====================
    Route::get('/{invoice}/validate-tax', [App\Http\Controllers\Api\InvoicePdfController::class, 'validateTaxIntegrity'])
        ->name('invoices.tax.validate');
    
    Route::get('/{invoice}/verify-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'verifyPdfIntegrity'])
        ->name('invoices.pdf.verify');
    
    Route::get('/{invoice}/compliance-status', [App\Http\Controllers\Api\InvoicePdfController::class, 'getComplianceStatus'])
        ->name('invoices.compliance.status');
    
    Route::get('/{invoice}/audit-trail', [App\Http\Controllers\Api\InvoicePdfController::class, 'getAuditTrail'])
        ->name('invoices.audit.trail');
    
    // ==================== Bulk Operations ====================
    Route::post('/bulk-generate-pdf', [App\Http\Controllers\Api\InvoicePdfController::class, 'bulkGeneratePdf'])
        ->name('invoices.pdf.bulk-generate');
    
    // ==================== Emergency Operations (Disabled) ====================
    // Route::post('/{invoice}/emergency-unlock', [App\Http\Controllers\Api\InvoicePdfController::class, 'emergencyUnlock'])
    //     ->middleware('admin')
    //     ->name('invoices.emergency.unlock');
});

/*
|--------------------------------------------------------------------------
| Wallet API Routes - SaaS Billing System
|--------------------------------------------------------------------------
|
| Wallet management endpoints for topup, balance checking, and transaction history.
| Core component of billing-first architecture.
|
*/

Route::prefix('wallet')->middleware('auth:sanctum')->group(function () {
    // Get wallet summary
    Route::get('/', [App\Http\Controllers\Api\WalletController::class, 'getWallet'])
        ->name('api.wallet.summary');
    
    // Get transaction history
    Route::get('/transactions', [App\Http\Controllers\Api\WalletController::class, 'getTransactions'])
        ->name('api.wallet.transactions');
    
    // Request topup (Client-only — Owner/Admin BLOCKED)
    Route::post('/topup', [App\Http\Controllers\Api\WalletController::class, 'requestTopup'])
        ->middleware('ensure.client')
        ->name('api.wallet.topup');
    
    // Check message sending eligibility
    Route::post('/check-eligibility', [App\Http\Controllers\Api\WalletController::class, 'checkMessageEligibility'])
        ->name('api.wallet.check-eligibility');
    
    // Get message rates
    Route::get('/rates', [App\Http\Controllers\Api\WalletController::class, 'getMessageRates'])
        ->name('api.wallet.rates');
    
    // Calculate message cost
    Route::post('/calculate-cost', [App\Http\Controllers\Api\WalletController::class, 'calculateCost'])
        ->name('api.wallet.calculate-cost');
});

/*
|--------------------------------------------------------------------------
| Payment Callback Routes
|--------------------------------------------------------------------------
|
| Webhook endpoints for payment gateway callbacks.
| These routes handle payment confirmations and update wallet balances.
|
*/

Route::prefix('payment-callbacks')->group(function () {
    // Midtrans payment callback
    Route::post('/midtrans', [App\Http\Controllers\PaymentCallbackController::class, 'midtransCallback'])
        ->name('payment.callback.midtrans');
    
    // Xendit payment callback
    Route::post('/xendit', [App\Http\Controllers\PaymentCallbackController::class, 'xenditCallback'])
        ->name('payment.callback.xendit');
});

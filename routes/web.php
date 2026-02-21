<?php

use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InfoUserController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ResetController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\TemplatePesanController;
use App\Http\Controllers\KontakController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\OwnerDashboardController;
use App\Http\Controllers\RiskApprovalController;
use App\Http\Controllers\Webhook\PlanWebhookController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\AccountUnlockController;
use App\Http\Controllers\ForcePasswordChangeController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\TopupInvoiceController;
use App\Http\Controllers\InvoiceWebController;
use App\Http\Controllers\BusinessMetricsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// ==================== PUBLIC LANDING PAGE ====================
Route::get('/', [LandingController::class, 'index'])->name('landing');

// ==================== SMART LOGIN ENTRY POINT ====================
// This route handles "Masuk" button clicks intelligently:
// - If already authenticated → redirect to appropriate dashboard
// - If guest → redirect to login page
// This prevents "stuck login" issues when session is active
Route::get('/masuk', [SessionsController::class, 'enter'])->name('enter');

/**
 * =====================================================================
 * MIDDLEWARE FLOW - LOCKED ORDER (DO NOT MODIFY WITHOUT SA APPROVAL)
 * =====================================================================
 * 
 * ARCHITECTURE:
 * 1. Guest routes (no auth)
 * 2. Auth-only routes (accessible during onboarding)
 * 3. Client-access routes (requires complete onboarding)
 * 
 * MIDDLEWARE GROUP 'client.access':
 * - auth → Guest redirect to /login
 * - domain.setup → Onboarding check (OWNER bypass, CLIENT enforce)
 * 
 * ANTI-LOOP GUARANTEE:
 * - Middleware handles ALL redirect logic
 * - Controllers NEVER redirect (except after form submit)
 * - Single source of truth: EnsureDomainSetup middleware
 * 
 * See: MIDDLEWARE_FLOW.md for complete documentation
 */

// ==================== AUTH-ONLY ROUTES ====================
// Accessible to authenticated users (even during onboarding)
Route::group(['middleware' => 'auth'], function () {

    Route::get('/home', [HomeController::class, 'home'])->name('home');
	
	// ==================== ONBOARDING (DOMAIN SETUP) ====================
	// Onboarding routes MUST be accessible even if setup incomplete
	// Middleware EnsureDomainSetup will:
	// - ALLOW if onboarding_complete = false
	// - BLOCK if onboarding_complete = true (redirect to dashboard)
	Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
	Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
	Route::get('api/onboarding/status', [OnboardingController::class, 'status'])->name('onboarding.status');
	Route::post('api/onboarding/step', [OnboardingController::class, 'completeStep'])->name('onboarding.step');
	Route::post('api/onboarding/activate', [OnboardingController::class, 'activate'])->name('onboarding.activate');
	Route::get('panduan', [OnboardingController::class, 'panduan'])->name('panduan');
	
	// Profile routes (accessible even if setup incomplete)
	Route::get('profile', function () {
		return view('profile');
	})->name('profile');
	Route::get('/user-profile', [InfoUserController::class, 'create']);
	Route::post('/user-profile', [InfoUserController::class, 'store']);
	
	// Logout (always accessible)
    Route::post('/logout', [SessionsController::class, 'destroy'])->name('logout');
});

// ==================== CLIENT ACCESS ROUTES (PROTECTED) ====================
// Requires complete onboarding (OWNER/ADMIN bypass automatic)
// Middleware: auth → domain.setup (locked order)
Route::middleware(['client.access'])->group(function () {
		
		// Dashboard - primary route after login
		Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
	
		// Pricing Page - redirect to subscription
		Route::get('pricing', function () {
			return redirect()->route('subscription.index');
		})->name('pricing');

		// Talkabiz Routes — Revenue Lock: subscription.active required
		Route::middleware(['subscription.active'])->group(function () {
			Route::get('inbox', function () {
				return view('inbox.index');
			})->name('inbox');
		});

		// Campaign Routes - GUARDED by onboarding + subscription
		Route::middleware(['campaign.guard', 'subscription.active'])->group(function () {
			Route::get('campaign', function () {
				return view('campaign');
			})->name('campaign');
			
			/*
			|----------------------------------------------------------------------
			| REVENUE GUARD 4-LAYER — Campaign Send Routes
			|----------------------------------------------------------------------
			| Layer 1: subscription.active (subscription check)
			| Layer 2: plan.limit:campaign (campaign + recipient quota)
			| Layer 3: wallet.cost.guard:campaign (wallet balance check)
			| Layer 4: Atomic deduction via RevenueGuardService (in Controller)
			|----------------------------------------------------------------------
			*/
			// Route::post('campaign/create', ...)->middleware(['subscription.active', 'plan.limit:campaign']);
			// Route::post('campaign/send', ...)->middleware(['subscription.active', 'plan.limit:campaign', 'wallet.cost.guard:campaign']);
		});

		// Template Routes (CRUD)
		Route::get('template', [TemplatePesanController::class, 'index'])->name('template');
		Route::post('template', [TemplatePesanController::class, 'store'])->name('template.store');
		Route::put('template/{id}', [TemplatePesanController::class, 'update'])->name('template.update');
		Route::delete('template/{id}', [TemplatePesanController::class, 'destroy'])->name('template.destroy');
		Route::get('api/template', [TemplatePesanController::class, 'list'])->name('template.list');

		// Kontak Routes (CRUD)
		Route::get('kontak', [KontakController::class, 'index'])->name('kontak');
		Route::post('kontak', [KontakController::class, 'store'])->name('kontak.store');
		Route::post('kontak/import', [KontakController::class, 'import'])->name('kontak.import');
		Route::delete('kontak/{id}', [KontakController::class, 'destroy'])->name('kontak.destroy');
		Route::get('api/kontak', [KontakController::class, 'list'])->name('kontak.list');

		// Billing Routes
		Route::get('billing', [BillingController::class, 'index'])->name('billing');
		Route::get('billing/upgrade', [BillingController::class, 'upgrade'])->name('billing.upgrade');

		// Subscription Routes (Paket & Langganan — separated from Billing/Wallet)
		Route::get('subscription', [\App\Http\Controllers\SubscriptionController::class, 'index'])->name('subscription.index');
		Route::post('subscription/checkout', [\App\Http\Controllers\SubscriptionController::class, 'checkout'])->name('subscription.checkout');
		// subscription/check-status REMOVED → Webhook-only architecture

		// Activation Funnel KPI Routes (Growth Engine)
		Route::post('api/activation/track', [\App\Http\Controllers\Api\ActivationController::class, 'track'])->name('activation.track');
		Route::post('api/activation/modal-shown', [\App\Http\Controllers\Api\ActivationController::class, 'modalShown'])->name('activation.modal-shown');

		Route::post('billing/topup', [BillingController::class, 'topUp'])->name('billing.topup');
		Route::get('api/billing/history', [BillingController::class, 'history'])->name('billing.history');
		Route::get('api/billing/wallet', [BillingController::class, 'getWalletInfo'])->name('billing.wallet');
		Route::get('api/billing/quota', [BillingController::class, 'getQuota'])->name('billing.quota');
		Route::post('billing/topup/{id}/confirm', [BillingController::class, 'confirmTopUp'])->name('billing.topup.confirm');
		Route::post('billing/topup/{id}/reject', [BillingController::class, 'rejectTopUp'])->name('billing.topup.reject');
		Route::post('billing/quick-topup', [BillingController::class, 'quickTopUp'])->name('billing.quick-topup');

		// LEGACY: /billing/plan routes removed — subscription is at /subscription
		// Redirect legacy URLs so old bookmarks/emails still work
		Route::get('billing/plan', fn() => redirect()->route('subscription.index'))->name('billing.plan.index');
		Route::get('billing/plan/{code}', fn() => redirect()->route('subscription.index'));

		// ==================== INVOICE ROUTES ====================

		// Topup Invoice Routes (specific prefix — MUST be before general {id} catch-all)
		Route::prefix('invoices/topup')->name('invoices.topup.')->group(function () {
			Route::get('/', [TopupInvoiceController::class, 'index'])->name('index');
			Route::get('{id}', [TopupInvoiceController::class, 'show'])->name('show');
			Route::get('{id}/pdf', [TopupInvoiceController::class, 'pdf'])->name('pdf');
			Route::get('{id}/download', [TopupInvoiceController::class, 'download'])->name('download');
		});

		// Unified Invoice Routes (all types: subscription, topup, recurring, upgrade)
		Route::prefix('invoices')->name('invoices.')->group(function () {
			Route::get('/', [InvoiceWebController::class, 'index'])->name('index');
			Route::get('{id}/pdf', [InvoiceWebController::class, 'pdf'])->name('pdf')->where('id', '[0-9]+');
			Route::get('{id}/download', [InvoiceWebController::class, 'download'])->name('download')->where('id', '[0-9]+');
			Route::get('{id}', [InvoiceWebController::class, 'show'])->name('show')->where('id', '[0-9]+');
		});

		// Topup Routes (SSOT for saldo management)
		Route::prefix('topup')->name('topup.')->group(function () {
			Route::get('/', [\App\Http\Controllers\TopupController::class, 'index'])->name('index');
			Route::get('modal', [\App\Http\Controllers\TopupController::class, 'modal'])->name('modal');
			Route::post('process', [\App\Http\Controllers\TopupController::class, 'process'])->name('process');
			Route::get('payment', function () {
				return view('topup.payment'); // TODO: implement payment page
			})->name('payment.process');
		});

		Route::get('activity-log', function () {
			return view('activity-log');
		})->name('activity-log');

		Route::get('settings', function () {
			return view('settings');
		})->name('settings');

		// Settings - Payment Gateway (Super Admin only)
		Route::get('settings/payment-gateway', [PaymentGatewayController::class, 'index'])->name('settings.payment-gateway');
		Route::post('settings/payment-gateway/midtrans', [PaymentGatewayController::class, 'updateMidtrans'])->name('settings.payment-gateway.update-midtrans');
		Route::post('settings/payment-gateway/xendit', [PaymentGatewayController::class, 'updateXendit'])->name('settings.payment-gateway.update-xendit');
		Route::post('settings/payment-gateway/set-active', [PaymentGatewayController::class, 'setActive'])->name('settings.payment-gateway.set-active');
		Route::post('settings/payment-gateway/test', [PaymentGatewayController::class, 'testConnection'])->name('settings.payment-gateway.test');

		
		// Owner Dashboard (Super Admin & Owner only)
		Route::get('owner/dashboard', [OwnerDashboardController::class, 'index'])->name('owner.dashboard');
		Route::get('api/owner/summary', [OwnerDashboardController::class, 'apiSummary'])->name('owner.api.summary');
		Route::get('api/owner/clients', [OwnerDashboardController::class, 'apiClientProfitability'])->name('owner.api.clients');
		Route::get('api/owner/usage', [OwnerDashboardController::class, 'apiUsageMonitor'])->name('owner.api.usage');
		Route::get('api/owner/flagged', [OwnerDashboardController::class, 'apiFlaggedClients'])->name('owner.api.flagged');
		Route::get('api/owner/trial-stats', [OwnerDashboardController::class, 'apiTrialStats'])->name('owner.api.trial-stats');
		Route::post('owner/client/{id}/limit', [OwnerDashboardController::class, 'limitClient'])->name('owner.client.limit');
		Route::post('owner/client/{id}/pause', [OwnerDashboardController::class, 'pauseClientCampaigns'])->name('owner.client.pause');
		Route::post('owner/refresh-cache', [OwnerDashboardController::class, 'refreshCache'])->name('owner.refresh-cache');
		
		// Risk Approval Panel (Owner & Super Admin only)
		Route::get('owner/risk-approval', [RiskApprovalController::class, 'index'])->name('risk-approval.index');
		Route::get('owner/risk-approval/{id}', [RiskApprovalController::class, 'show'])->name('risk-approval.show');
		Route::post('owner/risk-approval/{id}/approve', [RiskApprovalController::class, 'approve'])->name('risk-approval.approve');
		Route::post('owner/risk-approval/{id}/reject', [RiskApprovalController::class, 'reject'])->name('risk-approval.reject');
		Route::post('owner/risk-approval/{id}/suspend', [RiskApprovalController::class, 'suspend'])->name('risk-approval.suspend');
		Route::post('owner/risk-approval/{id}/reactivate', [RiskApprovalController::class, 'reactivate'])->name('risk-approval.reactivate');
		
		// Abuse Monitor Panel (Owner & Super Admin only)
		Route::get('owner/abuse-monitor', [App\Http\Controllers\AbuseMonitorController::class, 'index'])->name('abuse-monitor.index');
		Route::get('owner/abuse-monitor/{id}', [App\Http\Controllers\AbuseMonitorController::class, 'show'])->name('abuse-monitor.show');
		Route::post('owner/abuse-monitor/{id}/reset', [App\Http\Controllers\AbuseMonitorController::class, 'resetScore'])->name('abuse-monitor.reset');
		Route::post('owner/abuse-monitor/{id}/suspend', [App\Http\Controllers\AbuseMonitorController::class, 'suspendKlien'])->name('abuse-monitor.suspend');
		Route::post('owner/abuse-monitor/{id}/approve', [App\Http\Controllers\AbuseMonitorController::class, 'approveKlien'])->name('abuse-monitor.approve');
		
		// Risk Rules Panel (Owner & Super Admin only)
		Route::get('owner/risk-rules', [App\Http\Controllers\RiskRulesController::class, 'index'])->name('risk-rules.index');
		Route::post('owner/risk-rules/update', [App\Http\Controllers\RiskRulesController::class, 'updateSettings'])->name('risk-rules.update');
		Route::get('owner/risk-rules/escalation-history', [App\Http\Controllers\RiskRulesController::class, 'escalationHistory'])->name('risk-rules.escalation-history');
		Route::get('owner/risk-rules/escalation-history/data', [App\Http\Controllers\RiskRulesController::class, 'escalationHistoryData'])->name('risk-rules.escalation-history.data');
		
		// Rate Limit Rules Panel (Owner & Super Admin only)
		Route::get('owner/rate-limit-rules', [App\Http\Controllers\RateLimitRulesController::class, 'index'])->name('rate-limit-rules.index');
		Route::post('owner/rate-limit-rules/{id}/update', [App\Http\Controllers\RateLimitRulesController::class, 'updateRule'])->name('rate-limit-rules.update');
		Route::post('owner/rate-limit-rules/{id}/toggle', [App\Http\Controllers\RateLimitRulesController::class, 'toggleRule'])->name('rate-limit-rules.toggle');
		Route::post('owner/rate-limit-rules/create', [App\Http\Controllers\RateLimitRulesController::class, 'createRule'])->name('rate-limit-rules.create');
		Route::delete('owner/rate-limit-rules/{id}', [App\Http\Controllers\RateLimitRulesController::class, 'deleteRule'])->name('rate-limit-rules.delete');
		Route::get('owner/rate-limit-rules/statistics', [App\Http\Controllers\RateLimitRulesController::class, 'getStatistics'])->name('rate-limit-rules.statistics');
		Route::post('owner/rate-limit-rules/clear-logs', [App\Http\Controllers\RateLimitRulesController::class, 'clearLogs'])->name('rate-limit-rules.clear-logs');
		
		// Complaint Monitor Panel (Owner & Super Admin only)
		// Static routes MUST come before {id} wildcard to avoid route conflicts
		Route::get('owner/complaint-monitor', [App\Http\Controllers\ComplaintMonitorController::class, 'index'])->name('complaint-monitor.index');
		Route::get('owner/complaint-monitor/statistics/data', [App\Http\Controllers\ComplaintMonitorController::class, 'getStatistics'])->name('complaint-monitor.statistics');
		Route::get('owner/complaint-monitor/export/csv', [App\Http\Controllers\ComplaintMonitorController::class, 'export'])->name('complaint-monitor.export');
		Route::post('owner/complaint-monitor/bulk-action', [App\Http\Controllers\ComplaintMonitorController::class, 'bulkAction'])->name('complaint-monitor.bulk-action');
		Route::get('owner/complaint-monitor/{id}', [App\Http\Controllers\ComplaintMonitorController::class, 'show'])->name('complaint-monitor.show');
		Route::post('owner/complaint-monitor/{id}/suspend-klien', [App\Http\Controllers\ComplaintMonitorController::class, 'suspendKlien'])->name('complaint-monitor.suspend-klien');
		Route::post('owner/complaint-monitor/{id}/block-recipient', [App\Http\Controllers\ComplaintMonitorController::class, 'blockRecipient'])->name('complaint-monitor.block-recipient');
		Route::post('owner/complaint-monitor/{id}/mark-processed', [App\Http\Controllers\ComplaintMonitorController::class, 'markAsProcessed'])->name('complaint-monitor.mark-processed');
		Route::post('owner/complaint-monitor/{id}/dismiss', [App\Http\Controllers\ComplaintMonitorController::class, 'dismissComplaint'])->name('complaint-monitor.dismiss');
		
		// Compliance Log Panel (Owner & Super Admin only) — READ-ONLY
		Route::get('owner/compliance-log', [App\Http\Controllers\ComplianceLogController::class, 'index'])->name('compliance-log.index');
		Route::get('owner/compliance-log/export/csv', [App\Http\Controllers\ComplianceLogController::class, 'export'])->name('compliance-log.export');
		Route::get('owner/compliance-log/verify-integrity', [App\Http\Controllers\ComplianceLogController::class, 'verifyIntegrity'])->name('compliance-log.verify');
		Route::get('owner/compliance-log/{id}', [App\Http\Controllers\ComplianceLogController::class, 'show'])->name('compliance-log.show');
		
}); // End client.access middleware group

// ==================== SLA & SUPPORT SYSTEM ====================
// Include SLA Dashboard and Support routes
require __DIR__.'/sla-web.php';

// ==================== FORCE PASSWORD CHANGE ====================
Route::middleware(['auth'])->group(function () {
    Route::get('/change-password', [ForcePasswordChangeController::class, 'show'])
        ->name('password.force-change');
    Route::post('/change-password', [ForcePasswordChangeController::class, 'update'])
        ->name('password.force-change.update');
});

// ==================== ADMIN USER MANAGEMENT ====================
Route::middleware(['auth', 'force.password.change'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])
        ->name('users.reset-password');
    Route::post('/users/{user}/toggle-force-password', [UserManagementController::class, 'toggleForcePasswordChange'])
        ->name('users.toggle-force-password');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole'])
        ->name('users.update-role');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/invalidate-sessions', [UserManagementController::class, 'invalidateSessions'])
        ->name('users.invalidate-sessions');
    Route::get('/activity-log', [UserManagementController::class, 'activityLog'])->name('activity-log');
});

// ==================== WEBHOOK ROUTES (NO AUTH) ====================
// Midtrans Plan Webhook
Route::post('webhook/midtrans/plan', [PlanWebhookController::class, 'handle'])
    ->name('webhook.midtrans.plan');

// webhook/midtrans/plan/check REMOVED → Webhook-only architecture

// ==================== GUPSHUP WEBHOOK (HARDENED) ====================
// POST /webhook/gupshup/status - Status updates from Gupshup (HARDENED)
// Middleware: IP whitelist → HMAC signature → Replay attack prevention
Route::post('webhook/gupshup/status', [\App\Http\Controllers\Webhook\GupshupStatusWebhookController::class, 'handle'])
    ->middleware(['gupshup.ip', 'gupshup.signature', 'gupshup.replay'])
    ->name('webhook.gupshup.status');

// POST /webhook/gupshup/delivery - Delivery status updates (HARDENED)
// Receives: sent, delivered, read, failed status from Gupshup
Route::post('webhook/gupshup/delivery', [\App\Http\Controllers\Webhook\GupshupDeliveryWebhookController::class, 'handle'])
    ->middleware(['gupshup.ip', 'gupshup.signature', 'gupshup.replay'])
    ->name('webhook.gupshup.delivery');

// Snap Redirect Callbacks (after Midtrans payment)
Route::get('billing/plan/finish', [PlanWebhookController::class, 'finish'])
    ->name('billing.plan.finish');
Route::get('billing/plan/unfinish', [PlanWebhookController::class, 'unfinish'])
    ->name('billing.plan.unfinish');
Route::get('billing/plan/error', [PlanWebhookController::class, 'error'])
    ->name('billing.plan.error');


// ==================== CONTACT PAGE ====================
Route::get('/contact', function () {
    return view('contact');
})->name('contact');

Route::post('/contact', function (\Illuminate\Http\Request $request) {
    $request->validate([
        'name' => 'required|string|max:100',
        'email' => 'required|email|max:100',
        'company' => 'nullable|string|max:100',
        'message' => 'required|string|max:2000',
    ]);
    return back()->with('success', 'Pesan berhasil dikirim. Tim kami akan menghubungi Anda segera.');
})->name('contact.send');

Route::group(['middleware' => 'guest'], function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');
    Route::get('/login', [SessionsController::class, 'create'])->name('login');
    Route::post('/login', [SessionsController::class, 'store'])->name('login.store');
    Route::get('/login/forgot-password', [ResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ResetController::class, 'sendEmail'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetController::class, 'resetPass'])->name('password.reset');
    Route::post('/reset-password', [ChangePasswordController::class, 'changePassword'])->name('password.update');

    // Account Unlock (email-based)
    Route::post('/account/unlock/request', [AccountUnlockController::class, 'requestUnlock'])->name('account.unlock.request');
    Route::get('/account/unlock/{token}', [AccountUnlockController::class, 'verifyUnlock'])->name('account.unlock.verify');
});

// Include test wallet routes (remove in production)
if (app()->environment(['local', 'development', 'testing'])) {
    require __DIR__.'/test-wallet.php';
}

// ==================== INTERNAL METRICS (Prometheus) ====================
// Accessible ONLY from 127.0.0.1 — no auth middleware needed
Route::get('/internal/metrics/business', BusinessMetricsController::class)
    ->name('internal.metrics.business')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Debug: temporary route to verify plans data (HAPUS setelah selesai debug)
Route::get('/debug-plans', function () {
    return DB::table('plans')->get();
});

/*
|--------------------------------------------------------------------------
| DEV ONLY — Manual Midtrans Sandbox Settlement
|--------------------------------------------------------------------------
| Forces a sandbox transaction to settlement status.
| BLOCKED in production. Remove after testing.
*/
Route::post('/dev/midtrans/settle/{orderId}', function ($orderId) {

    if (app()->environment('production')) {
        abort(403, 'Not allowed in production');
    }

    $serverKey = config('services.midtrans.server_key');
    $url = "https://api.sandbox.midtrans.com/v2/{$orderId}/settlement";

    $response = Http::withBasicAuth($serverKey, '')
        ->post($url);

    \Log::info('Manual Midtrans settle', [
        'order_id' => $orderId,
        'response' => $response->json(),
    ]);

    return response()->json([
        'status' => $response->status(),
        'body' => $response->json(),
    ]);
});


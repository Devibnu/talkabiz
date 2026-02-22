<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        // \Fruitcake\Cors\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        
        /**
         * CLIENT ACCESS FLOW - LOCKED ORDER (DO NOT MODIFY WITHOUT SA APPROVAL)
         * 
         * URUTAN WAJIB untuk mencegah redirect loop:
         * 1. auth → Guest redirect to /login
         * 2. domain.setup → Check onboarding_complete (CLIENT only, OWNER bypass)
         * 
         * RULES:
         * - JANGAN ubah urutan
         * - JANGAN tambah redirect di middleware lain
         * - OWNER/ADMIN bypass automatic via domain.setup
         * 
         * FLOW:
         * Guest → /login
         * Auth → OWNER → bypass all checks → /dashboard
         * Auth → CLIENT → onboarding check:
         *   - incomplete → /onboarding only
         *   - complete → /dashboard & protected routes
         * 
         * See: MIDDLEWARE_FLOW.md for complete documentation
         */
        'client.access' => [
            'auth',           // Step 1: Authentication (guest → /login)
            'impersonate.client', // Step 1.5: Apply impersonation overrides (BEFORE domain.setup)
            'domain.setup',   // Step 2: Onboarding check (CLIENT only, OWNER bypass)
            'share.subscription.status', // Step 3: Share plan expiry data for banner display
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     * 
     * CRITICAL MIDDLEWARE (ORDER MATTERS):
     * - auth: Authentication check (guest → /login)
     * - domain.setup: Onboarding check (CLIENT only, OWNER bypass)
     * - campaign.guard: Campaign access (requires onboarding + subscription)
     * - cost.guard: Saldo check (action-specific, NO redirect to onboarding/dashboard)
     * 
     * See: MIDDLEWARE_FLOW.md for complete flow documentation
     */
    protected $routeMiddleware = [
        // ============ CORE AUTHENTICATION & ACCESS CONTROL ============
        'auth' => \App\Http\Middleware\Authenticate::class,
        'domain.setup' => \App\Http\Middleware\EnsureDomainSetup::class, // ONBOARDING CHECK (LOCKED)
        'impersonate.client' => \App\Http\Middleware\ImpersonateClient::class, // CLIENT IMPERSONATION (Owner-only)
        
        // ============ ROLE-BASED ACCESS ============
        'role' => \App\Http\Middleware\CheckRole::class,
        'ensure.owner' => \App\Http\Middleware\EnsureOwner::class,
        'ensure.client' => \App\Http\Middleware\EnsureClient::class,
        
        // ============ FEATURE & BILLING GUARDS ============
        'campaign.guard' => \App\Http\Middleware\CampaignGuardMiddleware::class,
        'cost.guard' => \App\Http\Middleware\CostGuard::class, // LEGACY SALDO GUARD (action-specific)
        'wallet.cost.guard' => \App\Http\Middleware\WalletCostGuard::class, // NEW Revenue Guard L3
        'risk.check' => \App\Http\Middleware\RiskCheck::class, // RISK ENGINE (business type based)
        'approval.guard' => \App\Http\Middleware\RiskApprovalGuard::class, // RISK APPROVAL (high-risk manual approval)
        'abuse.detect' => \App\Http\Middleware\AbuseDetection::class, // ABUSE DETECTION (behavior-based scoring)
        'ratelimit.adaptive' => \App\Http\Middleware\AdaptiveRateLimit::class, // ADAPTIVE RATE LIMIT (context-aware throttling)
        
        // ============ SUBSCRIPTION & PLAN ENFORCEMENT ============
        'subscription' => \App\Http\Middleware\EnsureActiveSubscription::class,
        'subscription.active' => \App\Http\Middleware\EnsureActiveSubscription::class,
        'feature' => \App\Http\Middleware\EnsureFeatureAccess::class,
        'quota' => \App\Http\Middleware\EnsureWithinQuota::class,
        'plan.limit' => \App\Http\Middleware\CheckPlanLimit::class,
        'can.send.campaign' => \App\Http\Middleware\EnsureCanSendCampaign::class, // Composite: campaign.guard + subscription.active + wallet.cost.guard
        
        // ============ VIEW DATA SHARING ============
        'share.subscription.status' => \App\Http\Middleware\ShareSubscriptionStatus::class,
        
        // ============ LEGACY / SPECIALIZED ============
        'corporate.pilot' => \App\Http\Middleware\CorporatePilotMiddleware::class,
        'force.password.change' => \App\Http\Middleware\ForcePasswordChange::class,
        'legal.acceptance' => \App\Http\Middleware\RequireLegalAcceptance::class,
        
        // ============ LARAVEL DEFAULTS ============
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        
        // ============ WEBHOOK SECURITY ============
        'webhook.gupshup' => \App\Http\Middleware\ValidateGupshupWebhook::class,
        
        // Gupshup Webhook Security (Hardened - Individual Middlewares)
        'gupshup.signature' => \App\Http\Middleware\VerifyGupshupSignature::class,
        'gupshup.ip' => \App\Http\Middleware\VerifyGupshupIP::class,
        'gupshup.replay' => \App\Http\Middleware\PreventReplayAttack::class,
        
        // Owner Access Control
        'ensure.owner' => \App\Http\Middleware\EnsureOwner::class,
        
        // Subscription Enforcement Core (SSOT via subscription.plan_snapshot)
        'subscription' => \App\Http\Middleware\EnsureActiveSubscription::class,
        'subscription.active' => \App\Http\Middleware\EnsureActiveSubscription::class,
        'feature' => \App\Http\Middleware\EnsureFeatureAccess::class,
        'quota' => \App\Http\Middleware\EnsureWithinQuota::class,
        
        // Billing & Cost Guard (prevent boncos)
        'cost.guard' => \App\Http\Middleware\CostGuard::class, // LEGACY — uses MetaCostService/DompetSaldo
        'wallet.cost.guard' => \App\Http\Middleware\WalletCostGuard::class, // NEW — Revenue Guard Layer 3 (Wallet system)
        
        // Legal & Terms Enforcement
        'legal.acceptance' => \App\Http\Middleware\RequireLegalAcceptance::class,
    ];
}

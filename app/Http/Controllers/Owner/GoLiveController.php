<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Go-Live Checklist Controller
 * 
 * Dashboard untuk melihat status production readiness.
 * 
 * Endpoints:
 * - GET  /owner/golive           - Dashboard view
 * - POST /owner/golive/refresh   - Refresh checks
 * - POST /owner/golive/command   - Run artisan command
 */
class GoLiveController extends Controller
{
    public function index()
    {
        $checks = $this->runAllChecks();
        
        // Calculate summary
        $passed = count(array_filter($checks, fn($c) => $c['passed']));
        $failed = count(array_filter($checks, fn($c) => !$c['passed'] && $c['severity'] !== 'warning'));
        $warnings = count(array_filter($checks, fn($c) => !$c['passed'] && $c['severity'] === 'warning'));
        $total = count($checks);
        
        $summary = [
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
            'total' => $total,
            'score' => $total > 0 ? round(($passed / $total) * 100) : 0,
            'ready' => $failed === 0,
        ];

        // Group by category
        $categories = $this->groupChecksByCategory($checks);

        return view('owner.golive.index', compact('summary', 'categories'));
    }

    public function refresh(): JsonResponse
    {
        $checks = $this->runAllChecks();
        
        $passed = count(array_filter($checks, fn($c) => $c['passed']));
        $failed = count(array_filter($checks, fn($c) => !$c['passed'] && $c['severity'] !== 'warning'));
        $total = count($checks);

        return response()->json([
            'success' => true,
            'summary' => [
                'passed' => $passed,
                'failed' => $failed,
                'total' => $total,
                'score' => $total > 0 ? round(($passed / $total) * 100) : 0,
            ],
        ]);
    }

    public function runCommand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'command' => 'required|string|in:config:cache,route:cache,view:cache,optimize:clear,cache:clear',
        ]);

        try {
            Artisan::call($validated['command']);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // CHECK METHODS
    // ==========================================

    protected function runAllChecks(): array
    {
        $checks = [];

        // SYSTEM CHECKS
        $checks[] = $this->check(
            'system', 'APP_ENV=production',
            config('app.env') === 'production',
            'APP_ENV: ' . config('app.env'),
            'Set APP_ENV=production di .env'
        );

        $checks[] = $this->check(
            'system', 'APP_DEBUG=false',
            config('app.debug') === false,
            'APP_DEBUG: ' . (config('app.debug') ? 'true' : 'false'),
            'Set APP_DEBUG=false di .env'
        );

        $checks[] = $this->check(
            'system', 'Queue worker running',
            $this->isQueueWorkerRunning(),
            'Check process list',
            'php artisan queue:work --daemon'
        );

        $checks[] = $this->check(
            'system', 'Scheduler configured',
            $this->isSchedulerConfigured(),
            'Check crontab',
            '* * * * * php artisan schedule:run'
        );

        $checks[] = $this->check(
            'system', 'Log rotation (daily)',
            in_array(config('logging.default'), ['daily', 'stack']),
            'Channel: ' . config('logging.default'),
            'Set LOG_CHANNEL=daily',
            'warning'
        );

        $checks[] = $this->check(
            'system', 'Database connected',
            $this->isDatabaseConnected(),
            'MySQL connection',
            'Check DB credentials'
        );

        // SECURITY CHECKS
        $checks[] = $this->check(
            'security', 'Webhook signature middleware',
            $this->hasMiddleware('gupshup.signature'),
            'Middleware registered',
            'Register GupshupSignature middleware'
        );

        $checks[] = $this->check(
            'security', 'Owner routes protected',
            $this->areOwnerRoutesProtected(),
            'Auth middleware on owner/*',
            'Add auth middleware to owner routes'
        );

        $checks[] = $this->check(
            'security', 'HTTPS enforced',
            str_starts_with(config('app.url'), 'https://'),
            'APP_URL: ' . config('app.url'),
            'Set APP_URL with https://',
            'warning'
        );

        // BILLING CHECKS
        $checks[] = $this->check(
            'billing', 'Payment gateway configured',
            !empty(config('services.midtrans.server_key')) || !empty(config('services.xendit.secret_key')),
            'Midtrans/Xendit configured',
            'Set payment gateway keys in .env'
        );

        $checks[] = $this->check(
            'billing', 'Pricing settings exist',
            DB::table('pricing_settings')->exists(),
            'pricing_settings table',
            'Run pricing migration'
        );

        $checks[] = $this->check(
            'billing', 'Plans available',
            DB::table('plans')->where('is_active', true)->exists(),
            DB::table('plans')->where('is_active', true)->count() . ' active plans',
            'Create at least 1 active plan'
        );

        $checks[] = $this->check(
            'billing', 'WA Pricing configured',
            DB::table('wa_pricing')->count() >= 4,
            DB::table('wa_pricing')->count() . ' categories',
            'Run wa_pricing seeder'
        );

        // WHATSAPP CHECKS
        $checks[] = $this->check(
            'whatsapp', 'Gupshup API configured',
            !empty(config('services.gupshup.api_key')),
            config('services.gupshup.api_key') ? 'Configured' : 'NOT SET',
            'Set GUPSHUP_API_KEY in .env'
        );

        $connectedCount = DB::table('whatsapp_connections')->where('status', 'connected')->count();
        $checks[] = $this->check(
            'whatsapp', 'WhatsApp connected',
            $connectedCount > 0,
            $connectedCount . ' connected numbers',
            'Connect at least 1 WA number',
            'warning'
        );

        $checks[] = $this->check(
            'whatsapp', 'Health Score system',
            Schema::hasTable('whatsapp_health_scores'),
            'Table exists',
            'Run health score migration'
        );

        $checks[] = $this->check(
            'whatsapp', 'Warmup system',
            Schema::hasTable('whatsapp_warmups'),
            'Table exists',
            'Run warmup migration'
        );

        // UX CHECKS
        $checks[] = $this->check(
            'ux', 'Owner/Client route separation',
            File::exists(base_path('routes/owner.php')),
            'routes/owner.php exists',
            'Create owner routes file'
        );

        $checks[] = $this->check(
            'ux', 'Error pages (404, 500)',
            File::exists(resource_path('views/errors/404.blade.php')),
            'Custom error pages',
            'Create error page templates',
            'warning'
        );

        // MONITORING CHECKS
        $checks[] = $this->check(
            'monitoring', 'Profit Dashboard',
            File::exists(resource_path('views/owner/profit/index.blade.php')),
            'View exists',
            'Create profit dashboard view'
        );

        $checks[] = $this->check(
            'monitoring', 'Cost tracking tables',
            Schema::hasTable('cost_history') || Schema::hasTable('meta_costs'),
            'Cost tables exist',
            'Run cost tracking migration'
        );

        $checks[] = $this->check(
            'monitoring', 'Alert system',
            Schema::hasTable('alert_logs') || Schema::hasTable('pricing_alerts'),
            'Alert tables exist',
            'Run alert migration'
        );

        $checks[] = $this->check(
            'monitoring', 'Notification channel',
            !empty(config('services.telegram.bot_token')) || !empty(config('mail.from.address')),
            'Telegram/Email configured',
            'Set TELEGRAM_BOT_TOKEN or MAIL_*',
            'warning'
        );

        return $checks;
    }

    protected function check(
        string $category,
        string $name,
        bool $passed,
        ?string $detail = null,
        ?string $fix = null,
        string $severity = 'error'
    ): array {
        return [
            'category' => $category,
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
            'fix' => $fix,
            'severity' => $passed ? 'pass' : $severity,
        ];
    }

    protected function groupChecksByCategory(array $checks): array
    {
        $categoryInfo = [
            'system' => [
                'icon' => '1️⃣',
                'title' => 'SYSTEM CHECK',
                'description' => 'Environment, queue, scheduler, database',
            ],
            'security' => [
                'icon' => '2️⃣',
                'title' => 'SECURITY CHECK',
                'description' => 'Webhook validation, rate limit, route protection',
            ],
            'billing' => [
                'icon' => '3️⃣',
                'title' => 'BILLING & PAYMENT',
                'description' => 'Payment gateway, pricing, plans',
            ],
            'whatsapp' => [
                'icon' => '4️⃣',
                'title' => 'WHATSAPP FLOW',
                'description' => 'Gupshup, connections, health score, warmup',
            ],
            'ux' => [
                'icon' => '5️⃣',
                'title' => 'UX FINAL',
                'description' => 'Owner/Client mode, error handling',
            ],
            'monitoring' => [
                'icon' => '6️⃣',
                'title' => 'OWNER MONITORING',
                'description' => 'Profit dashboard, cost tracking, alerts',
            ],
        ];

        $grouped = [];

        foreach ($categoryInfo as $key => $info) {
            $categoryChecks = array_filter($checks, fn($c) => $c['category'] === $key);
            
            $grouped[$key] = array_merge($info, [
                'checks' => array_values($categoryChecks),
                'passed' => count(array_filter($categoryChecks, fn($c) => $c['passed'])),
                'failed' => count(array_filter($categoryChecks, fn($c) => !$c['passed'] && $c['severity'] !== 'warning')),
                'warnings' => count(array_filter($categoryChecks, fn($c) => !$c['passed'] && $c['severity'] === 'warning')),
            ]);
        }

        return $grouped;
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    protected function isQueueWorkerRunning(): bool
    {
        try {
            $result = Process::run('ps aux | grep "[q]ueue:work" | wc -l');
            return (int) trim($result->output()) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function isSchedulerConfigured(): bool
    {
        try {
            $result = Process::run('crontab -l 2>/dev/null | grep "schedule:run" | wc -l');
            return (int) trim($result->output()) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function isDatabaseConnected(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function hasMiddleware(string $name): bool
    {
        $middlewares = app('router')->getMiddleware();
        return isset($middlewares[$name]);
    }

    protected function areOwnerRoutesProtected(): bool
    {
        $routes = app('router')->getRoutes();
        foreach ($routes as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'owner/')) {
                $middlewares = $route->middleware();
                if (!in_array('auth', $middlewares) && !in_array('owner', $middlewares)) {
                    return false;
                }
            }
        }
        return true;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Validate ENV Configuration Command
 * 
 * Memeriksa semua konfigurasi ENV yang diperlukan untuk produksi.
 */
class ValidateEnvConfigCommand extends Command
{
    protected $signature = 'env:validate {--fix : Attempt to fix common issues}';

    protected $description = 'Validate all required ENV configurations for production';

    // Required ENV variables
    protected array $requiredEnv = [
        'APP_KEY' => 'Application encryption key',
        'APP_URL' => 'Application URL',
        'DB_HOST' => 'Database host',
        'DB_DATABASE' => 'Database name',
    ];

    // Payment gateway related
    protected array $paymentEnv = [
        'MIDTRANS_SERVER_KEY' => 'Midtrans Server Key (fallback)',
        'MIDTRANS_CLIENT_KEY' => 'Midtrans Client Key (fallback)',
        'MIDTRANS_ENV' => 'Midtrans environment (sandbox/production)',
    ];

    // Webhook security
    protected array $webhookEnv = [
        'GUPSHUP_WEBHOOK_SECRET' => 'Gupshup webhook HMAC secret',
        'GUPSHUP_API_KEY' => 'Gupshup API key',
    ];

    // Alert system
    protected array $alertEnv = [
        'TELEGRAM_BOT_TOKEN' => 'Telegram bot token for alerts',
        'TELEGRAM_CHAT_ID' => 'Telegram chat ID for alerts',
        'ALERT_OWNER_EMAIL' => 'Owner email for alerts',
    ];

    public function handle()
    {
        $this->info('🔍 Validating ENV Configuration for Talkabiz SaaS');
        $this->info(str_repeat('=', 60));
        $this->newLine();

        $errors = 0;
        $warnings = 0;

        // ==================== REQUIRED ENV ====================
        $this->info('1️⃣  Required ENV Variables');
        foreach ($this->requiredEnv as $key => $desc) {
            $value = env($key);
            if (empty($value)) {
                $this->error("   ❌ {$key}: NOT SET ({$desc})");
                $errors++;
            } else {
                $this->line("   ✅ {$key}: " . $this->maskValue($value, $key));
            }
        }
        $this->newLine();

        // ==================== PAYMENT GATEWAY ====================
        $this->info('2️⃣  Payment Gateway Configuration');
        
        // Check database config first
        $gateway = PaymentGateway::getActive();
        if ($gateway) {
            $this->line("   ✅ Active Gateway: {$gateway->display_name} ({$gateway->environment})");
            if ($gateway->isConfigured()) {
                $this->line("   ✅ API Keys: Configured (encrypted in DB)");
            } else {
                $this->warn("   ⚠️  API Keys: NOT CONFIGURED");
                $warnings++;
            }
        } else {
            $this->warn("   ⚠️  No active payment gateway in database");
            $warnings++;
            
            // Check ENV fallback
            foreach ($this->paymentEnv as $key => $desc) {
                $value = env($key);
                if (empty($value)) {
                    $this->warn("   ⚠️  {$key}: NOT SET (fallback - {$desc})");
                    $warnings++;
                } else {
                    $this->line("   ✅ {$key}: " . $this->maskValue($value, $key));
                }
            }
        }
        $this->newLine();

        // ==================== WEBHOOK SECURITY ====================
        $this->info('3️⃣  Webhook Security Configuration');
        foreach ($this->webhookEnv as $key => $desc) {
            $value = env($key);
            if (empty($value)) {
                $this->error("   ❌ {$key}: NOT SET - SECURITY RISK ({$desc})");
                $errors++;
            } else {
                $this->line("   ✅ {$key}: " . $this->maskValue($value, $key));
            }
        }
        $this->newLine();

        // ==================== ALERT SYSTEM ====================
        $this->info('4️⃣  Alert System Configuration');
        $telegramEnabled = env('ALERT_TELEGRAM_ENABLED', true);
        $emailEnabled = env('ALERT_EMAIL_ENABLED', true);

        if ($telegramEnabled) {
            $token = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID');
            
            if (empty($token)) {
                $this->warn("   ⚠️  TELEGRAM_BOT_TOKEN: NOT SET");
                $warnings++;
            } else {
                $this->line("   ✅ TELEGRAM_BOT_TOKEN: " . $this->maskValue($token, 'TELEGRAM_BOT_TOKEN'));
            }
            
            if (empty($chatId)) {
                $this->warn("   ⚠️  TELEGRAM_CHAT_ID: NOT SET");
                $warnings++;
            } else {
                $this->line("   ✅ TELEGRAM_CHAT_ID: {$chatId}");
            }

            // Test Telegram connection
            if (!empty($token) && !empty($chatId) && $this->confirm('   Test Telegram connection?', false)) {
                $this->testTelegramConnection($token, $chatId);
            }
        } else {
            $this->line("   ℹ️  Telegram alerts: DISABLED");
        }

        if ($emailEnabled) {
            $ownerEmail = env('ALERT_OWNER_EMAIL');
            if (empty($ownerEmail)) {
                $this->warn("   ⚠️  ALERT_OWNER_EMAIL: NOT SET");
                $warnings++;
            } else {
                $this->line("   ✅ ALERT_OWNER_EMAIL: {$ownerEmail}");
            }

            // Check mail config
            $mailHost = env('MAIL_HOST');
            if (empty($mailHost)) {
                $this->warn("   ⚠️  MAIL_HOST: NOT SET - Email alerts won't work");
                $warnings++;
            } else {
                $this->line("   ✅ MAIL_HOST: {$mailHost}");
            }
        } else {
            $this->line("   ℹ️  Email alerts: DISABLED");
        }
        $this->newLine();

        // ==================== SUMMARY ====================
        $this->info(str_repeat('=', 60));
        $this->info('📊 SUMMARY');
        
        if ($errors > 0) {
            $this->error("   ❌ Errors: {$errors}");
        } else {
            $this->line("   ✅ Errors: 0");
        }
        
        if ($warnings > 0) {
            $this->warn("   ⚠️  Warnings: {$warnings}");
        } else {
            $this->line("   ✅ Warnings: 0");
        }
        $this->newLine();

        if ($errors > 0) {
            $this->error('❌ Configuration NOT READY for production');
            $this->error('   Please fix the errors above before deployment.');
            return 1;
        } elseif ($warnings > 0) {
            $this->warn('⚠️  Configuration has warnings');
            $this->warn('   Review the warnings above. Some features may not work.');
            return 0;
        } else {
            $this->info('✅ Configuration READY for production');
            return 0;
        }
    }

    private function maskValue(string $value, string $key): string
    {
        // Don't mask non-sensitive values
        $nonSensitive = ['APP_URL', 'MIDTRANS_ENV', 'DB_HOST', 'DB_DATABASE', 'MAIL_HOST'];
        if (in_array($key, $nonSensitive)) {
            return $value;
        }

        // Mask sensitive values
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('•', strlen($value) - 8) . substr($value, -4);
    }

    private function testTelegramConnection(string $token, string $chatId): void
    {
        $this->line('   🔄 Testing Telegram connection...');
        
        try {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text' => "🔔 Talkabiz ENV Validation Test\n\nThis is a test message from env:validate command.\n\nTime: " . now()->format('Y-m-d H:i:s'),
                'parse_mode' => 'HTML',
            ]);

            if ($response->successful() && $response->json('ok')) {
                $this->info('   ✅ Telegram connection successful! Check your Telegram.');
            } else {
                $error = $response->json('description') ?? 'Unknown error';
                $this->error("   ❌ Telegram error: {$error}");
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Telegram connection failed: " . $e->getMessage());
        }
    }
}

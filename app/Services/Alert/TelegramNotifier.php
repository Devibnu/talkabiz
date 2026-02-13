<?php

namespace App\Services\Alert;

use App\Models\AlertLog;
use App\Models\AlertSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * TelegramNotifier - Send alerts to Telegram
 * 
 * Format pesan menggunakan Markdown untuk formatting yang lebih baik.
 * 
 * CONTOH FORMAT:
 * ==============
 * 
 * 🚨 *CRITICAL: Margin Kritis*
 * ━━━━━━━━━━━━━━━━━
 * 📋 *Type:* Profit Alert
 * 👤 *Klien:* PT ABC
 * 
 * Margin hari ini hanya 5% (threshold: 10%)
 * Revenue: Rp 1.000.000
 * Cost: Rp 950.000
 * 
 * 🕐 2026-02-02 10:30:00 WIB
 * ━━━━━━━━━━━━━━━━━
 */
class TelegramNotifier
{
    protected string $apiBaseUrl = 'https://api.telegram.org/bot';

    /**
     * Send alert to Telegram
     */
    public function send(AlertLog $alert, AlertSetting $settings): array
    {
        try {
            // Validate settings
            if (empty($settings->telegram_chat_id)) {
                return [
                    'success' => false,
                    'error' => 'Telegram chat_id not configured',
                ];
            }

            // Get bot token from settings or config
            $botToken = $settings->telegram_bot_token ?? config('services.telegram.bot_token');
            
            if (empty($botToken)) {
                return [
                    'success' => false,
                    'error' => 'Telegram bot_token not configured',
                ];
            }

            // Format message
            $message = $this->formatMessage($alert);

            // Send to Telegram
            $response = Http::timeout(10)->post(
                "{$this->apiBaseUrl}{$botToken}/sendMessage",
                [
                    'chat_id' => $settings->telegram_chat_id,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ]
            );

            if ($response->successful()) {
                Log::channel('alerts')->info('Telegram notification sent', [
                    'alert_id' => $alert->id,
                    'chat_id' => $settings->telegram_chat_id,
                ]);

                return [
                    'success' => true,
                    'message_id' => $response->json('result.message_id'),
                ];
            }

            $error = $response->json('description') ?? 'Unknown Telegram API error';
            
            Log::channel('alerts')->error('Telegram notification failed', [
                'alert_id' => $alert->id,
                'error' => $error,
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $error,
            ];

        } catch (Exception $e) {
            Log::channel('alerts')->error('Telegram notification exception', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format alert message for Telegram
     */
    protected function formatMessage(AlertLog $alert): string
    {
        $levelEmoji = $this->getLevelEmoji($alert->level);
        $typeEmoji = $this->getTypeEmoji($alert->type);
        $levelLabel = strtoupper($alert->level);

        $lines = [];

        // Header
        $lines[] = "{$levelEmoji} *{$levelLabel}: {$this->escapeMarkdown($alert->title)}*";
        $lines[] = "━━━━━━━━━━━━━━━━━";

        // Meta info
        $lines[] = "{$typeEmoji} *Type:* {$alert->type_label}";

        // Add klien info if available
        if ($alert->context && isset($alert->context['klien_name'])) {
            $lines[] = "👤 *Klien:* {$this->escapeMarkdown($alert->context['klien_name'])}";
        }

        // Add connection info if available
        if ($alert->context && isset($alert->context['phone_number'])) {
            $lines[] = "📱 *Nomor:* {$alert->context['phone_number']}";
        }

        $lines[] = "";

        // Message body
        $lines[] = $this->escapeMarkdown($alert->message);

        $lines[] = "";

        // Additional context for specific alert types
        if ($alert->type === AlertLog::TYPE_PROFIT) {
            $this->addProfitContext($lines, $alert);
        } elseif ($alert->type === AlertLog::TYPE_QUOTA) {
            $this->addQuotaContext($lines, $alert);
        } elseif ($alert->type === AlertLog::TYPE_SECURITY) {
            $this->addSecurityContext($lines, $alert);
        }

        // Timestamp
        $lines[] = "━━━━━━━━━━━━━━━━━";
        $lines[] = "🕐 " . now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s') . " WIB";

        // Occurrence count if deduplicated
        if ($alert->occurrence_count > 1) {
            $lines[] = "🔄 Terjadi {$alert->occurrence_count}x";
        }

        return implode("\n", $lines);
    }

    /**
     * Add profit-specific context
     */
    protected function addProfitContext(array &$lines, AlertLog $alert): void
    {
        $context = $alert->context ?? [];

        if (isset($context['margin_percent'])) {
            $lines[] = "📊 *Margin:* {$context['margin_percent']}%";
        }
        if (isset($context['revenue'])) {
            $lines[] = "💵 *Revenue:* Rp " . number_format($context['revenue'], 0, ',', '.');
        }
        if (isset($context['cost'])) {
            $lines[] = "💸 *Cost:* Rp " . number_format($context['cost'], 0, ',', '.');
        }
        if (isset($context['profit'])) {
            $emoji = $context['profit'] >= 0 ? '✅' : '❌';
            $lines[] = "{$emoji} *Profit:* Rp " . number_format($context['profit'], 0, ',', '.');
        }
    }

    /**
     * Add quota-specific context
     */
    protected function addQuotaContext(array &$lines, AlertLog $alert): void
    {
        $context = $alert->context ?? [];

        if (isset($context['remaining_percent'])) {
            $lines[] = "📊 *Sisa:* {$context['remaining_percent']}%";
        }
        if (isset($context['monthly_used']) && isset($context['monthly_limit'])) {
            $lines[] = "📈 *Terpakai:* " . number_format($context['monthly_used']) . " / " . number_format($context['monthly_limit']);
        }
    }

    /**
     * Add security-specific context
     */
    protected function addSecurityContext(array &$lines, AlertLog $alert): void
    {
        $context = $alert->context ?? [];

        if (isset($context['ip'])) {
            $lines[] = "🌐 *IP:* `{$context['ip']}`";
        }
        if (isset($context['endpoint'])) {
            $lines[] = "🔗 *Endpoint:* `{$context['endpoint']}`";
        }
        if (isset($context['user_agent'])) {
            $ua = substr($context['user_agent'], 0, 50);
            $lines[] = "🖥️ *UA:* {$this->escapeMarkdown($ua)}...";
        }
    }

    /**
     * Get emoji for level
     */
    protected function getLevelEmoji(string $level): string
    {
        return match ($level) {
            AlertLog::LEVEL_CRITICAL => '🚨',
            AlertLog::LEVEL_WARNING => '⚠️',
            AlertLog::LEVEL_INFO => 'ℹ️',
            default => '📋',
        };
    }

    /**
     * Get emoji for type
     */
    protected function getTypeEmoji(string $type): string
    {
        return match ($type) {
            AlertLog::TYPE_PROFIT => '💰',
            AlertLog::TYPE_WA_STATUS => '📱',
            AlertLog::TYPE_QUOTA => '📊',
            AlertLog::TYPE_SECURITY => '🔒',
            default => '📋',
        };
    }

    /**
     * Escape Markdown special characters
     */
    protected function escapeMarkdown(string $text): string
    {
        // Characters that need escaping in Telegram Markdown
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, "\\{$char}", $text);
        }

        return $text;
    }

    /**
     * Test Telegram connection
     */
    public function testConnection(AlertSetting $settings): array
    {
        try {
            $botToken = $settings->telegram_bot_token ?? config('services.telegram.bot_token');
            
            if (empty($botToken)) {
                return ['success' => false, 'error' => 'Bot token not configured'];
            }

            if (empty($settings->telegram_chat_id)) {
                return ['success' => false, 'error' => 'Chat ID not configured'];
            }

            $response = Http::timeout(10)->post(
                "{$this->apiBaseUrl}{$botToken}/sendMessage",
                [
                    'chat_id' => $settings->telegram_chat_id,
                    'text' => "✅ *Talkabiz Alert Test*\n\nKoneksi Telegram berhasil!\n🕐 " . now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    'parse_mode' => 'Markdown',
                ]
            );

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Test message sent successfully'];
            }

            return [
                'success' => false,
                'error' => $response->json('description') ?? 'Unknown error',
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

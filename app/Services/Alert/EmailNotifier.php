<?php

namespace App\Services\Alert;

use App\Models\AlertLog;
use App\Models\AlertSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * EmailNotifier - Send alerts via Email
 * 
 * Email digunakan sebagai:
 * 1. Fallback jika Telegram gagal
 * 2. Summary/digest harian
 * 3. Notifikasi untuk level tertentu
 */
class EmailNotifier
{
    /**
     * Send alert email
     */
    public function send(AlertLog $alert, AlertSetting $settings): array
    {
        try {
            // Validate settings
            $email = $settings->email_address ?? config('mail.owner_email');
            
            if (empty($email)) {
                return [
                    'success' => false,
                    'error' => 'Email address not configured',
                ];
            }

            // Build email content
            $subject = $this->buildSubject($alert);
            $htmlContent = $this->buildHtmlContent($alert);
            $textContent = $this->buildTextContent($alert);

            // Send email
            Mail::send([], [], function ($message) use ($email, $subject, $htmlContent, $textContent) {
                $message->to($email)
                    ->subject($subject)
                    ->html($htmlContent)
                    ->text($textContent);
            });

            Log::channel('alerts')->info('Email notification sent', [
                'alert_id' => $alert->id,
                'email' => $email,
            ]);

            return [
                'success' => true,
            ];

        } catch (Exception $e) {
            Log::channel('alerts')->error('Email notification failed', [
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
     * Build email subject
     */
    protected function buildSubject(AlertLog $alert): string
    {
        $levelPrefix = match ($alert->level) {
            AlertLog::LEVEL_CRITICAL => '🚨 [CRITICAL]',
            AlertLog::LEVEL_WARNING => '⚠️ [WARNING]',
            AlertLog::LEVEL_INFO => 'ℹ️ [INFO]',
            default => '[ALERT]',
        };

        return "{$levelPrefix} {$alert->title} - Talkabiz";
    }

    /**
     * Build HTML email content
     */
    protected function buildHtmlContent(AlertLog $alert): string
    {
        $levelColor = match ($alert->level) {
            AlertLog::LEVEL_CRITICAL => '#dc3545',
            AlertLog::LEVEL_WARNING => '#ffc107',
            AlertLog::LEVEL_INFO => '#17a2b8',
            default => '#6c757d',
        };

        $context = $alert->context ?? [];
        $contextHtml = '';

        if (!empty($context)) {
            $contextHtml = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px;">';
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $contextHtml .= '<tr>';
                $contextHtml .= '<td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; width: 150px;">' . ucfirst(str_replace('_', ' ', $key)) . '</td>';
                $contextHtml .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars((string) $value) . '</td>';
                $contextHtml .= '</tr>';
            }
            $contextHtml .= '</table>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$alert->title}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    
    <!-- Header -->
    <div style="background: {$levelColor}; color: white; padding: 20px; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0; font-size: 24px;">{$alert->level_icon} {$alert->title}</h1>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">{$alert->type_label}</p>
    </div>
    
    <!-- Body -->
    <div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none;">
        
        <!-- Message -->
        <div style="background: white; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <p style="margin: 0; white-space: pre-wrap;">{$alert->message}</p>
        </div>
        
        <!-- Context -->
        {$contextHtml}
        
        <!-- Timestamp -->
        <p style="color: #666; font-size: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            🕐 Alert Time: {$alert->created_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s')} WIB
            <br>
            🔖 Alert ID: #{$alert->id}
        </p>
    </div>
    
    <!-- Footer -->
    <div style="background: #333; color: white; padding: 15px; border-radius: 0 0 8px 8px; text-align: center; font-size: 12px;">
        <p style="margin: 0;">Talkabiz Alert System</p>
        <p style="margin: 5px 0 0 0; opacity: 0.7;">This is an automated notification. Please do not reply.</p>
    </div>
    
</body>
</html>
HTML;
    }

    /**
     * Build plain text email content
     */
    protected function buildTextContent(AlertLog $alert): string
    {
        $lines = [];
        $lines[] = strtoupper($alert->level) . ": " . $alert->title;
        $lines[] = str_repeat('=', 50);
        $lines[] = "";
        $lines[] = "Type: " . $alert->type_label;
        $lines[] = "";
        $lines[] = $alert->message;
        $lines[] = "";

        if (!empty($alert->context)) {
            $lines[] = str_repeat('-', 30);
            $lines[] = "Additional Information:";
            foreach ($alert->context as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $lines[] = "  " . ucfirst(str_replace('_', ' ', $key)) . ": " . $value;
            }
            $lines[] = "";
        }

        $lines[] = str_repeat('-', 30);
        $lines[] = "Alert Time: " . $alert->created_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s') . " WIB";
        $lines[] = "Alert ID: #" . $alert->id;
        $lines[] = "";
        $lines[] = "-- ";
        $lines[] = "Talkabiz Alert System";

        return implode("\n", $lines);
    }

    /**
     * Send daily digest
     */
    public function sendDailyDigest(AlertSetting $settings): array
    {
        try {
            $email = $settings->email_address ?? config('mail.owner_email');
            
            if (empty($email)) {
                return ['success' => false, 'error' => 'Email not configured'];
            }

            // Get today's alerts
            $alerts = AlertLog::whereDate('created_at', today())
                ->orderByDesc('level')
                ->orderByDesc('created_at')
                ->get();

            if ($alerts->isEmpty()) {
                return ['success' => true, 'message' => 'No alerts to digest'];
            }

            // Group by type and level
            $criticalCount = $alerts->where('level', AlertLog::LEVEL_CRITICAL)->count();
            $warningCount = $alerts->where('level', AlertLog::LEVEL_WARNING)->count();
            $infoCount = $alerts->where('level', AlertLog::LEVEL_INFO)->count();

            $subject = "📊 Daily Alert Digest - " . today()->format('Y-m-d');
            
            if ($criticalCount > 0) {
                $subject = "🚨 {$criticalCount} Critical Alerts - Daily Digest " . today()->format('Y-m-d');
            }

            $htmlContent = $this->buildDigestHtml($alerts, $criticalCount, $warningCount, $infoCount);

            Mail::send([], [], function ($message) use ($email, $subject, $htmlContent) {
                $message->to($email)
                    ->subject($subject)
                    ->html($htmlContent);
            });

            Log::channel('alerts')->info('Daily digest sent', [
                'email' => $email,
                'alert_count' => $alerts->count(),
            ]);

            return [
                'success' => true,
                'alert_count' => $alerts->count(),
            ];

        } catch (Exception $e) {
            Log::channel('alerts')->error('Daily digest failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build digest HTML
     */
    protected function buildDigestHtml($alerts, int $criticalCount, int $warningCount, int $infoCount): string
    {
        $alertRows = '';
        foreach ($alerts as $alert) {
            $levelColor = match ($alert->level) {
                AlertLog::LEVEL_CRITICAL => '#dc3545',
                AlertLog::LEVEL_WARNING => '#ffc107',
                AlertLog::LEVEL_INFO => '#17a2b8',
                default => '#6c757d',
            };

            $alertRows .= <<<HTML
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <span style="background: {$levelColor}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                        {$alert->level}
                    </span>
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">{$alert->type_label}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">{$alert->title}</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">{$alert->created_at->format('H:i')}</td>
            </tr>
HTML;
        }

        $totalAlerts = $alerts->count();
        $date = today()->format('Y-m-d');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daily Alert Digest</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px;">
    
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0;">📊 Daily Alert Digest</h1>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">{$date} - Talkabiz</p>
    </div>
    
    <div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none;">
        
        <!-- Summary -->
        <div style="display: flex; gap: 15px; margin-bottom: 20px;">
            <div style="flex: 1; background: #dc3545; color: white; padding: 15px; border-radius: 4px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold;">{$criticalCount}</div>
                <div style="font-size: 12px;">Critical</div>
            </div>
            <div style="flex: 1; background: #ffc107; color: #333; padding: 15px; border-radius: 4px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold;">{$warningCount}</div>
                <div style="font-size: 12px;">Warning</div>
            </div>
            <div style="flex: 1; background: #17a2b8; color: white; padding: 15px; border-radius: 4px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold;">{$infoCount}</div>
                <div style="font-size: 12px;">Info</div>
            </div>
            <div style="flex: 1; background: #6c757d; color: white; padding: 15px; border-radius: 4px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold;">{$totalAlerts}</div>
                <div style="font-size: 12px;">Total</div>
            </div>
        </div>
        
        <!-- Alert Table -->
        <table style="width: 100%; border-collapse: collapse; background: white;">
            <thead>
                <tr style="background: #eee;">
                    <th style="padding: 10px; text-align: left;">Level</th>
                    <th style="padding: 10px; text-align: left;">Type</th>
                    <th style="padding: 10px; text-align: left;">Title</th>
                    <th style="padding: 10px; text-align: left;">Time</th>
                </tr>
            </thead>
            <tbody>
                {$alertRows}
            </tbody>
        </table>
        
    </div>
    
    <div style="background: #333; color: white; padding: 15px; border-radius: 0 0 8px 8px; text-align: center; font-size: 12px;">
        <p style="margin: 0;">Talkabiz Alert System - Daily Digest</p>
    </div>
    
</body>
</html>
HTML;
    }

    /**
     * Test email connection
     */
    public function testConnection(AlertSetting $settings): array
    {
        try {
            $email = $settings->email_address ?? config('mail.owner_email');
            
            if (empty($email)) {
                return ['success' => false, 'error' => 'Email not configured'];
            }

            Mail::raw(
                "Talkabiz Alert System\n\nEmail connection test successful!\n\nTime: " . now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s') . " WIB",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('✅ Talkabiz Alert Email Test');
                }
            );

            return ['success' => true, 'message' => 'Test email sent successfully'];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

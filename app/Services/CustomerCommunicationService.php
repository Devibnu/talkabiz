<?php

namespace App\Services;

use App\Models\StatusIncident;
use App\Models\StatusUpdate;
use App\Models\ScheduledMaintenance;
use App\Models\SystemComponent;
use App\Models\CustomerNotification;
use App\Models\NotificationSubscription;
use App\Models\InAppBanner;
use App\Models\User;
use App\Jobs\SendStatusNotificationJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * CUSTOMER COMMUNICATION SERVICE
 * 
 * Handles all customer-facing communications related to status.
 * 
 * Prinsip Komunikasi:
 * - Transparan tapi tidak panik
 * - Bahasa sederhana, non-teknis
 * - Tidak menyalahkan pihak manapun
 * - Proaktif, tidak menunggu user komplain
 * - Tidak spam - hanya kirim yang relevan
 */
class CustomerCommunicationService
{
    // ==================== RATE LIMITING ====================
    private const MIN_UPDATE_INTERVAL_MINUTES = 15;
    private const MAX_NOTIFICATIONS_PER_DAY = 10;

    public function __construct(
        private StatusPageService $statusPageService
    ) {}

    // ==================== INCIDENT NOTIFICATIONS ====================

    /**
     * Notify customers about new incident
     */
    public function notifyIncidentCreated(StatusIncident $incident): int
    {
        if (!$incident->is_published) {
            return 0;
        }

        $message = $this->generateIncidentNotice($incident);
        $subject = "🚨 {$incident->title}";

        return $this->sendNotifications(
            CustomerNotification::TYPE_INCIDENT_NOTICE,
            $incident,
            $subject,
            $message,
            $this->getAffectedUsers($incident)
        );
    }

    /**
     * Notify customers about incident update
     */
    public function notifyIncidentUpdate(StatusIncident $incident, StatusUpdate $update): int
    {
        if (!$incident->is_published || !$update->is_published) {
            return 0;
        }

        $emoji = match ($update->status) {
            StatusIncident::STATUS_IDENTIFIED => '🔍',
            StatusIncident::STATUS_MONITORING => '👀',
            StatusIncident::STATUS_RESOLVED => '✅',
            default => '📢',
        };

        $type = $update->status === StatusIncident::STATUS_RESOLVED
            ? CustomerNotification::TYPE_INCIDENT_RESOLVED
            : CustomerNotification::TYPE_INCIDENT_UPDATE;

        $subject = "{$emoji} Update: {$incident->title}";

        return $this->sendNotifications(
            $type,
            $incident,
            $subject,
            $update->message,
            $this->getAffectedUsers($incident)
        );
    }

    /**
     * Generate incident notice message
     */
    public function generateIncidentNotice(StatusIncident $incident): string
    {
        $components = $incident->getAffectedComponentModels()->pluck('name')->join(', ');
        $impact = $this->getImpactDescription($incident->impact);

        $message = "Kami sedang mengalami kendala pada layanan.\n\n";
        $message .= "📍 Layanan terdampak: {$components}\n";
        $message .= "📊 Dampak: {$impact}\n\n";
        
        if ($incident->summary) {
            $message .= "ℹ️ {$incident->summary}\n\n";
        }

        $message .= "Tim kami sedang bekerja untuk menyelesaikan kendala ini. ";
        $message .= "Kami akan memberikan update berkala.\n\n";
        $message .= "Terima kasih atas kesabaran Anda.";

        return $message;
    }

    /**
     * Get human-friendly impact description
     */
    private function getImpactDescription(string $impact): string
    {
        return match ($impact) {
            StatusIncident::IMPACT_CRITICAL => 'Sebagian besar pengguna tidak dapat mengakses layanan',
            StatusIncident::IMPACT_MAJOR => 'Beberapa pengguna mengalami kendala akses',
            StatusIncident::IMPACT_MINOR => 'Sebagian kecil pengguna mungkin mengalami keterlambatan',
            default => 'Dampak minimal pada layanan',
        };
    }

    // ==================== MAINTENANCE NOTIFICATIONS ====================

    /**
     * Notify customers about scheduled maintenance
     */
    public function notifyMaintenanceScheduled(ScheduledMaintenance $maintenance): int
    {
        if (!$maintenance->is_published) {
            return 0;
        }

        $subject = "📅 Pemeliharaan Terjadwal: {$maintenance->title}";
        $message = $maintenance->getScheduledNotice();

        return $this->sendNotifications(
            CustomerNotification::TYPE_MAINTENANCE_SCHEDULED,
            $maintenance,
            $subject,
            $message,
            $this->getAffectedUsersForMaintenance($maintenance)
        );
    }

    /**
     * Notify customers about maintenance starting
     */
    public function notifyMaintenanceStarted(ScheduledMaintenance $maintenance): int
    {
        if (!$maintenance->is_published) {
            return 0;
        }

        $subject = "🔧 Pemeliharaan Dimulai: {$maintenance->title}";
        $message = $maintenance->getStartedNotice();

        return $this->sendNotifications(
            CustomerNotification::TYPE_MAINTENANCE_STARTED,
            $maintenance,
            $subject,
            $message,
            $this->getAffectedUsersForMaintenance($maintenance)
        );
    }

    /**
     * Notify customers about maintenance completed
     */
    public function notifyMaintenanceCompleted(ScheduledMaintenance $maintenance): int
    {
        if (!$maintenance->is_published) {
            return 0;
        }

        $subject = "✅ Pemeliharaan Selesai: {$maintenance->title}";
        $message = $maintenance->getCompletedNotice();

        return $this->sendNotifications(
            CustomerNotification::TYPE_MAINTENANCE_COMPLETED,
            $maintenance,
            $subject,
            $message,
            $this->getAffectedUsersForMaintenance($maintenance)
        );
    }

    // ==================== STATUS CHANGE NOTIFICATIONS ====================

    /**
     * Notify about component status change (only for degradation)
     */
    public function notifyStatusChange(SystemComponent $component, string $previousStatus): int
    {
        // Only notify on degradation, not on recovery
        $prevSeverity = SystemComponent::STATUS_SEVERITY[$previousStatus] ?? 0;
        $newSeverity = $component->status_severity;

        if ($newSeverity <= $prevSeverity) {
            return 0; // No notification for improvement
        }

        // Only notify for significant changes (partial outage or worse)
        if ($newSeverity < SystemComponent::STATUS_SEVERITY[SystemComponent::STATUS_PARTIAL_OUTAGE]) {
            return 0;
        }

        $subject = "⚠️ Status Layanan: {$component->name}";
        $message = "Layanan {$component->name} mengalami {$component->status_label}.\n\n";
        $message .= "Kami sedang memantau situasi ini. Anda dapat melihat status terbaru di halaman status.\n\n";
        $message .= "Terima kasih atas pengertian Anda.";

        // Get users subscribed to status changes for this component
        $subscriptions = NotificationSubscription::active()
            ->forStatusChanges()
            ->get()
            ->filter(fn($s) => $s->shouldNotifyForComponent($component->slug));

        $users = User::whereIn('id', $subscriptions->pluck('user_id'))->get();

        return $this->sendNotificationsToUsers(
            CustomerNotification::TYPE_STATUS_CHANGE,
            $component,
            $subject,
            $message,
            $users
        );
    }

    // ==================== IN-APP BANNERS ====================

    /**
     * Get active banners for user
     */
    public function getBannersForUser(int $userId): array
    {
        return InAppBanner::getActiveForUser($userId)
            ->map(fn($b) => $b->toPublicArray())
            ->toArray();
    }

    /**
     * Dismiss banner for user
     */
    public function dismissBanner(int $bannerId, int $userId): bool
    {
        $banner = InAppBanner::find($bannerId);
        if (!$banner) {
            return false;
        }

        return $banner->dismissForUser($userId);
    }

    // ==================== NOTIFICATION DISPATCH ====================

    /**
     * Send notifications to affected users
     */
    private function sendNotifications(
        string $type,
        $notifiable,
        string $subject,
        string $message,
        Collection $users
    ): int {
        $count = 0;

        foreach ($users as $user) {
            $subscriptions = NotificationSubscription::where('user_id', $user->id)
                ->active()
                ->get()
                ->filter(fn($s) => $s->wantsNotificationType($type));

            foreach ($subscriptions as $subscription) {
                if ($this->shouldSendNotification($user->id, $subscription->channel)) {
                    $this->dispatchNotification(
                        $type,
                        $subscription->channel,
                        $user,
                        $notifiable,
                        $subject,
                        $message
                    );
                    $count++;
                }
            }
        }

        Log::info('CustomerCommunication: Notifications dispatched', [
            'type' => $type,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => $notifiable->id,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Send notifications to specific users
     */
    private function sendNotificationsToUsers(
        string $type,
        $notifiable,
        string $subject,
        string $message,
        Collection $users
    ): int {
        return $this->sendNotifications($type, $notifiable, $subject, $message, $users);
    }

    /**
     * Dispatch single notification
     */
    private function dispatchNotification(
        string $type,
        string $channel,
        User $user,
        $notifiable,
        string $subject,
        string $message
    ): CustomerNotification {
        $notification = CustomerNotification::create([
            'notification_type' => $type,
            'channel' => $channel,
            'user_id' => $user->id,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => $notifiable->id,
            'subject' => $subject,
            'message' => $message,
            'status' => CustomerNotification::STATUS_PENDING,
        ]);

        // Dispatch job to actually send
        SendStatusNotificationJob::dispatch($notification);

        return $notification;
    }

    /**
     * Check if notification should be sent (rate limiting)
     */
    private function shouldSendNotification(int $userId, string $channel): bool
    {
        // Check daily limit
        $todayCount = CustomerNotification::where('user_id', $userId)
            ->where('channel', $channel)
            ->whereDate('created_at', today())
            ->count();

        if ($todayCount >= self::MAX_NOTIFICATIONS_PER_DAY) {
            return false;
        }

        return true;
    }

    // ==================== USER TARGETING ====================

    /**
     * Get users affected by incident
     * In production, this would filter by users who use affected components
     */
    private function getAffectedUsers(StatusIncident $incident): Collection
    {
        // Get users with active subscriptions for incidents
        $subscriptions = NotificationSubscription::active()
            ->forIncidents()
            ->get();

        // Filter by component if specified
        if (!empty($incident->affected_components)) {
            $componentSlugs = SystemComponent::whereIn('id', $incident->affected_components)
                ->pluck('slug')
                ->toArray();

            $subscriptions = $subscriptions->filter(function ($s) use ($componentSlugs) {
                foreach ($componentSlugs as $slug) {
                    if ($s->shouldNotifyForComponent($slug)) {
                        return true;
                    }
                }
                return false;
            });
        }

        return User::whereIn('id', $subscriptions->pluck('user_id'))->get();
    }

    /**
     * Get users affected by maintenance
     */
    private function getAffectedUsersForMaintenance(ScheduledMaintenance $maintenance): Collection
    {
        $subscriptions = NotificationSubscription::active()
            ->forMaintenances()
            ->get();

        if (!empty($maintenance->affected_components)) {
            $componentSlugs = SystemComponent::whereIn('id', $maintenance->affected_components)
                ->pluck('slug')
                ->toArray();

            $subscriptions = $subscriptions->filter(function ($s) use ($componentSlugs) {
                foreach ($componentSlugs as $slug) {
                    if ($s->shouldNotifyForComponent($slug)) {
                        return true;
                    }
                }
                return false;
            });
        }

        return User::whereIn('id', $subscriptions->pluck('user_id'))->get();
    }

    // ==================== MESSAGE TEMPLATES ====================

    /**
     * Get investigating template
     */
    public function getInvestigatingTemplate(string $component, string $impact): string
    {
        return StatusUpdate::investigatingTemplate($component, $impact);
    }

    /**
     * Get identified template
     */
    public function getIdentifiedTemplate(string $cause, string $action): string
    {
        return StatusUpdate::identifiedTemplate($cause, $action);
    }

    /**
     * Get monitoring template
     */
    public function getMonitoringTemplate(): string
    {
        return StatusUpdate::monitoringTemplate();
    }

    /**
     * Get resolved template
     */
    public function getResolvedTemplate(string $summary): string
    {
        return StatusUpdate::resolvedTemplate($summary);
    }

    // ==================== EMAIL TEMPLATES ====================

    /**
     * Generate email HTML for incident
     */
    public function generateIncidentEmailHtml(StatusIncident $incident): string
    {
        $components = $incident->getAffectedComponentModels()->pluck('name')->join(', ');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8d7da; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .header.resolved { background: #d4edda; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .badge-danger { background: #dc3545; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-success { background: #28a745; color: white; }
        .content { padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .footer { margin-top: 20px; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">🚨 {$incident->title}</h2>
            <span class="badge badge-{$incident->impact_color}">{$incident->impact_label}</span>
        </div>
        
        <div class="content">
            <p><strong>Status:</strong> {$incident->status_label}</p>
            <p><strong>Layanan terdampak:</strong> {$components}</p>
            <p><strong>Dimulai:</strong> {$incident->started_at->format('d M Y H:i')} WIB</p>
            
            <hr>
            
            <p>{$incident->summary}</p>
        </div>
        
        <p style="text-align: center; margin-top: 20px;">
            <a href="/status" class="btn">Lihat Status Lengkap</a>
        </p>
        
        <div class="footer">
            <p>Email ini dikirim karena Anda berlangganan notifikasi status Talkabiz.</p>
            <p><a href="/settings/notifications">Kelola preferensi notifikasi</a></p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate email HTML for maintenance
     */
    public function generateMaintenanceEmailHtml(ScheduledMaintenance $maintenance): string
    {
        $components = $maintenance->getAffectedComponentModels()->pluck('name')->join(', ');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #cce5ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .content { padding: 15px; background: #f9f9f9; border-radius: 8px; }
        .footer { margin-top: 20px; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">📅 Pemeliharaan Terjadwal</h2>
        </div>
        
        <div class="content">
            <h3>{$maintenance->title}</h3>
            
            <p><strong>📆 Tanggal:</strong> {$maintenance->scheduled_start->format('l, d F Y')}</p>
            <p><strong>🕐 Waktu:</strong> {$maintenance->scheduled_start->format('H:i')} - {$maintenance->scheduled_end->format('H:i')} WIB</p>
            <p><strong>⏱️ Durasi:</strong> {$maintenance->scheduled_duration}</p>
            <p><strong>📍 Layanan terdampak:</strong> {$components}</p>
            <p><strong>📊 Dampak:</strong> {$maintenance->impact_label}</p>
            
            <hr>
            
            <p>{$maintenance->description}</p>
        </div>
        
        <p style="text-align: center; margin-top: 20px;">
            <a href="/status" class="btn">Lihat Detail</a>
        </p>
        
        <div class="footer">
            <p>Email ini dikirim karena Anda berlangganan notifikasi status Talkabiz.</p>
            <p><a href="/settings/notifications">Kelola preferensi notifikasi</a></p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    // ==================== ANNOUNCEMENT ====================

    /**
     * Send general announcement
     */
    public function sendAnnouncement(
        string $title,
        string $message,
        ?array $targetUserIds = null,
        array $channels = ['in_app', 'email']
    ): int {
        $count = 0;

        // Create in-app banner
        if (in_array('in_app', $channels)) {
            InAppBanner::create([
                'banner_type' => InAppBanner::TYPE_ANNOUNCEMENT,
                'severity' => InAppBanner::SEVERITY_INFO,
                'title' => $title,
                'message' => $message,
                'is_dismissible' => true,
                'is_active' => true,
                'target_users' => $targetUserIds,
                'starts_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
            $count++;
        }

        // Send email notifications
        if (in_array('email', $channels)) {
            $subscriptions = NotificationSubscription::active()
                ->byChannel(CustomerNotification::CHANNEL_EMAIL)
                ->forAnnouncements()
                ->when($targetUserIds, fn($q) => $q->whereIn('user_id', $targetUserIds))
                ->get();

            foreach ($subscriptions as $subscription) {
                $user = $subscription->user;
                if ($user) {
                    $this->dispatchNotification(
                        CustomerNotification::TYPE_GENERAL_ANNOUNCEMENT,
                        CustomerNotification::CHANNEL_EMAIL,
                        $user,
                        new \stdClass(), // Dummy notifiable
                        "📢 {$title}",
                        $message
                    );
                    $count++;
                }
            }
        }

        Log::info('CustomerCommunication: Announcement sent', [
            'title' => $title,
            'channels' => $channels,
            'target_users' => $targetUserIds ? count($targetUserIds) : 'all',
            'notifications' => $count,
        ]);

        return $count;
    }
}

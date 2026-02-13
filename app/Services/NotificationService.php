<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Exception;

class NotificationService
{
    /**
     * Send notification to specific user
     */
    public function sendToUser(int $userId, array $notificationData): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                Log::warning("User not found for notification", ['user_id' => $userId]);
                return false;
            }

            // Store in-app notification in database
            $this->storeInAppNotification($userId, $notificationData);
            
            // Trigger realtime notification (websocket/pusher)
            $this->triggerRealtimeNotification($userId, $notificationData);
            
            Log::info("In-app notification sent to user", [
                'user_id' => $userId,
                'alert_type' => $notificationData['type'] ?? 'unknown'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error("Failed to send notification to user", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send notification to all owners/admins
     */
    public function sendToOwners(array $notificationData): bool
    {
        try {
            $owners = User::where('role', 'owner')
                ->orWhere('role', 'admin')
                ->get();

            $successCount = 0;
            foreach ($owners as $owner) {
                if ($this->sendToUser($owner->id, $notificationData)) {
                    $successCount++;
                }
            }
            
            Log::info("Owner notification sent", [
                'owners_count' => $owners->count(),
                'success_count' => $successCount,
                'alert_type' => $notificationData['type'] ?? 'unknown'
            ]);
            
            return $successCount > 0;
            
        } catch (Exception $e) {
            Log::error("Failed to send notification to owners", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send email notification
     */
    public function sendEmailNotification(int $userId, array $emailData): bool
    {
        try {
            $user = User::find($userId);
            if (!$user || !$user->email) {
                Log::warning("User not found or no email for email notification", ['user_id' => $userId]);
                return false;
            }

            // TODO: Implement actual email sending
            // Example: $user->notify(new AlertEmailNotification($emailData));
            
            Log::info("Email notification sent", [
                'user_id' => $userId,
                'email' => $user->email,
                'alert_type' => $emailData['type'] ?? 'unknown'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error("Failed to send email notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Store in-app notification in database
     */
    private function storeInAppNotification(int $userId, array $notificationData): void
    {
        try {
            DB::table('user_notifications')->insert([
                'user_id' => $userId,
                'type' => 'alert',
                'title' => $notificationData['title'] ?? '',
                'message' => $notificationData['message'] ?? '',
                'data' => json_encode($notificationData),
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } catch (Exception $e) {
            Log::error("Failed to store in-app notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger realtime notification via websocket/pusher
     */
    private function triggerRealtimeNotification(int $userId, array $notificationData): void
    {
        try {
            // TODO: Implement websocket/pusher broadcasting
            // Example: broadcast(new AlertNotification($userId, $notificationData));
            
            // Untuk sekarang simpan di cache untuk polling
            $cacheKey = "user_alerts_{$userId}";
            $existingAlerts = Cache::get($cacheKey, []);
            
            $existingAlerts[] = [
                'id' => uniqid('alert_'),
                'timestamp' => now()->toISOString(),
                'data' => $notificationData
            ];
            
            // Keep only last 50 alerts in cache
            if (count($existingAlerts) > 50) {
                $existingAlerts = array_slice($existingAlerts, -50);
            }
            
            Cache::put($cacheKey, $existingAlerts, now()->addHours(24));
            
        } catch (Exception $e) {
            Log::error("Failed to trigger realtime notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get unread notifications for user
     */
    public function getUnreadNotifications(int $userId): Collection
    {
        try {
            return collect(DB::table('user_notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get());
        } catch (Exception $e) {
            Log::error("Failed to get unread notifications", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return collect();
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $userId, int $notificationId): bool
    {
        try {
            $affected = DB::table('user_notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                    'updated_at' => now()
                ]);

            return $affected > 0;
        } catch (Exception $e) {
            Log::error("Failed to mark notification as read", [
                'user_id' => $userId,
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark all notifications as read for user
     */
    public function markAllAsRead(int $userId): int
    {
        try {
            return DB::table('user_notifications')
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                    'updated_at' => now()
                ]);
        } catch (Exception $e) {
            Log::error("Failed to mark all notifications as read", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get notification counts for user
     */
    public function getNotificationCounts(int $userId): array
    {
        try {
            $counts = DB::table('user_notifications')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                    SUM(CASE WHEN is_read = 0 AND JSON_EXTRACT(data, "$.severity") = "critical" THEN 1 ELSE 0 END) as unread_critical
                ')
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDays(30))
                ->first();

            return [
                'total' => (int) ($counts->total ?? 0),
                'unread' => (int) ($counts->unread ?? 0),
                'unread_critical' => (int) ($counts->unread_critical ?? 0)
            ];
        } catch (Exception $e) {
            Log::error("Failed to get notification counts", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['total' => 0, 'unread' => 0, 'unread_critical' => 0];
        }
    }

    /**
     * Cleanup old notifications
     */
    public function cleanupOldNotifications(int $daysToKeep = 90): int
    {
        try {
            $deletedCount = DB::table('user_notifications')
                ->where('created_at', '<', now()->subDays($daysToKeep))
                ->delete();

            Log::info("Cleaned up old notifications", [
                'deleted_count' => $deletedCount,
                'days_to_keep' => $daysToKeep
            ]);

            return $deletedCount;
        } catch (Exception $e) {
            Log::error("Failed to cleanup old notifications", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
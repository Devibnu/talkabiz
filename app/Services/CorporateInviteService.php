<?php

namespace App\Services;

use App\Models\CorporateClient;
use App\Models\CorporateInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Corporate Invite Service
 * 
 * Handle the invite-only flow for corporate clients:
 * - Admin sends invite
 * - Client accepts via unique token
 * - Admin approves → corporate_active
 * 
 * JANGAN:
 * - Tampilkan pricing corporate publik
 * - Buka self-register corporate
 * - Mass invite
 */
class CorporateInviteService
{
    /**
     * Send invite to potential corporate client.
     * 
     * @param string $email
     * @param string $companyName
     * @param string $contactPerson
     * @param int $adminId
     * @param string|null $notes
     * @param array $customLimits Optional custom limits
     * @return CorporateInvite
     */
    public function sendInvite(
        string $email,
        string $companyName,
        string $contactPerson,
        int $adminId,
        ?string $notes = null,
        array $customLimits = []
    ): CorporateInvite {
        // Check for existing pending invite
        $existingInvite = CorporateInvite::where('email', $email)
            ->where('status', CorporateInvite::STATUS_PENDING)
            ->first();

        if ($existingInvite) {
            throw new \Exception("Pending invite already exists for {$email}");
        }

        // Check if email already has corporate account
        $existingUser = User::where('email', $email)
            ->where('corporate_pilot', true)
            ->first();

        if ($existingUser) {
            throw new \Exception("User {$email} is already a corporate client");
        }

        // Create invite
        $invite = CorporateInvite::create([
            'email' => $email,
            'company_name' => $companyName,
            'contact_person' => $contactPerson,
            'invite_token' => CorporateInvite::generateToken(),
            'status' => CorporateInvite::STATUS_PENDING,
            'invited_by' => $adminId,
            'invite_expires_at' => now()->addDays(7), // 7 days expiry
            'notes' => $notes,
        ]);

        // TODO: Send email notification
        // Mail::to($email)->send(new CorporateInviteMail($invite));

        return $invite;
    }

    /**
     * Accept invite and create corporate client.
     * 
     * @param string $token
     * @param int $userId
     * @return CorporateClient
     */
    public function acceptInvite(string $token, int $userId): CorporateClient
    {
        $invite = CorporateInvite::where('invite_token', $token)->first();

        if (!$invite) {
            throw new \Exception('Invite not found');
        }

        if (!$invite->isValid()) {
            throw new \Exception('Invite is no longer valid');
        }

        $user = User::findOrFail($userId);

        // Verify email matches
        if ($user->email !== $invite->email) {
            throw new \Exception('Email does not match invite');
        }

        return DB::transaction(function () use ($invite, $user) {
            // Mark invite as accepted
            $invite->markAsAccepted($user);

            // Update user
            $user->update([
                'corporate_pilot' => true,
                'corporate_status' => 'pending', // Needs admin approval
                'corporate_pilot_invited_at' => $invite->created_at,
                'corporate_pilot_invited_by' => $invite->invited_by,
                'corporate_pilot_notes' => $invite->notes,
            ]);

            // Create corporate client record
            $customLimits = $invite->custom_limits ?? [];
            
            $client = CorporateClient::create([
                'user_id' => $user->id,
                'company_name' => $invite->company_name,
                'contact_person' => $invite->contact_person,
                'contact_email' => $invite->email,
                'status' => CorporateClient::STATUS_PENDING,
                // Apply custom limits if set
                'limit_messages_monthly' => $customLimits['messages_monthly'] ?? null,
                'limit_messages_daily' => $customLimits['messages_daily'] ?? null,
                'limit_messages_hourly' => $customLimits['messages_hourly'] ?? null,
                'limit_wa_numbers' => $customLimits['wa_numbers'] ?? null,
                'limit_active_campaigns' => $customLimits['active_campaigns'] ?? null,
                'limit_recipients_per_campaign' => $customLimits['recipients_per_campaign'] ?? null,
                // Default SLA
                'sla_priority_queue' => false,
                'sla_max_retries' => 3,
                'sla_target_delivery_rate' => 95,
                'sla_max_latency_seconds' => 300, // 5 minutes
            ]);

            // Log activity
            $client->logActivity(
                'invite_accepted',
                'general',
                "Invite accepted by {$user->name}",
                null,
                'client'
            );

            return $client;
        });
    }

    /**
     * Resend invite email.
     */
    public function resendInvite(int $inviteId, int $adminId): CorporateInvite
    {
        $invite = CorporateInvite::findOrFail($inviteId);

        if ($invite->status !== CorporateInvite::STATUS_PENDING) {
            throw new \Exception('Cannot resend non-pending invite');
        }

        // Generate new token and extend expiry
        $invite->update([
            'invite_token' => CorporateInvite::generateToken(),
            'invite_expires_at' => now()->addDays(7),
        ]);

        // TODO: Resend email
        // Mail::to($invite->email)->send(new CorporateInviteMail($invite));

        return $invite;
    }

    /**
     * Revoke an invite.
     */
    public function revokeInvite(int $inviteId, int $adminId): CorporateInvite
    {
        $invite = CorporateInvite::findOrFail($inviteId);

        if ($invite->status !== CorporateInvite::STATUS_PENDING) {
            throw new \Exception('Cannot revoke non-pending invite');
        }

        $invite->revoke();

        return $invite;
    }

    /**
     * Validate invite token.
     */
    public function validateToken(string $token): ?CorporateInvite
    {
        $invite = CorporateInvite::where('invite_token', $token)->first();

        if (!$invite || !$invite->isValid()) {
            return null;
        }

        return $invite;
    }

    /**
     * Get invite by token for display.
     */
    public function getInviteByToken(string $token): ?CorporateInvite
    {
        return CorporateInvite::where('invite_token', $token)
            ->with('invitedByAdmin')
            ->first();
    }

    /**
     * Get pending invites.
     */
    public function getPendingInvites()
    {
        return CorporateInvite::pending()
            ->with('invitedByAdmin')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get invite statistics.
     */
    public function getInviteStats(): array
    {
        return [
            'pending' => CorporateInvite::pending()->count(),
            'accepted' => CorporateInvite::where('status', CorporateInvite::STATUS_ACCEPTED)->count(),
            'expired' => CorporateInvite::where('status', CorporateInvite::STATUS_EXPIRED)->count(),
            'revoked' => CorporateInvite::where('status', CorporateInvite::STATUS_REVOKED)->count(),
        ];
    }

    /**
     * Clean up expired invites.
     */
    public function cleanupExpiredInvites(): int
    {
        return CorporateInvite::pending()
            ->where('expires_at', '<', now())
            ->update(['status' => CorporateInvite::STATUS_EXPIRED]);
    }
}

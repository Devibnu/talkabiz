<?php

namespace App\Services;

use App\Helpers\SecurityLog;
use App\Models\Klien;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * ClientContextService — Enterprise Client Impersonation Manager
 * 
 * Manages the impersonation lifecycle for Owner viewing client accounts.
 * Session-based, request-scoped, transparent to all downstream code.
 * 
 * ARCHITECTURE:
 * - Owner triggers impersonation via ImpersonationController
 * - Session stores `impersonate_klien_id` + metadata
 * - ImpersonateClient middleware calls applyImpersonation() early in pipeline
 * - User::getAttribute() override transparently resolves klien_id, plan fields
 * - ALL 120+ existing call sites (controllers, services, middleware) work unchanged
 * 
 * SECURITY:
 * - Only role='owner' or 'super_admin' can impersonate
 * - Cannot impersonate another owner/admin
 * - Cannot impersonate self (own klien_id)
 * - All events logged to security channel
 * - Cleared on logout (session invalidation)
 * 
 * @package App\Services
 */
class ClientContextService
{
    /** Session key for impersonated klien ID */
    private const SESSION_KEY_KLIEN_ID = 'impersonate_klien_id';
    
    /** Session key for impersonation metadata */
    private const SESSION_KEY_META = 'impersonate_meta';
    
    /** Roles allowed to impersonate */
    private const ALLOWED_ROLES = ['owner', 'super_admin', 'superadmin'];
    
    /** Roles that CANNOT be impersonated (their klien) */
    private const PROTECTED_ROLES = ['owner', 'super_admin', 'superadmin', 'admin'];

    /**
     * Start impersonating a client.
     * 
     * @param User $actor The owner/admin performing the impersonation
     * @param int $klienId The Klien ID to impersonate
     * @return array{success: bool, message: string, klien?: Klien}
     */
    public function startImpersonation(User $actor, int $klienId): array
    {
        // Guard: Only allowed roles
        if (!in_array($actor->role, self::ALLOWED_ROLES, true)) {
            SecurityLog::warning('IMPERSONATION_DENIED_ROLE', [
                'actor_id' => $actor->id,
                'actor_role' => $actor->role,
                'target_klien_id' => $klienId,
                'reason' => 'Role not allowed to impersonate',
            ]);
            return ['success' => false, 'message' => 'Hanya Owner yang dapat melakukan impersonasi.'];
        }

        // Guard: Cannot impersonate own klien
        if ($actor->getRawOriginal('klien_id') === $klienId) {
            return ['success' => false, 'message' => 'Tidak dapat impersonasi akun sendiri.'];
        }

        // Guard: Klien must exist
        $klien = Klien::find($klienId);
        if (!$klien) {
            return ['success' => false, 'message' => 'Client tidak ditemukan.'];
        }

        // Guard: Cannot impersonate an owner/admin's klien
        $protectedUser = User::where('klien_id', $klienId)
            ->whereIn('role', self::PROTECTED_ROLES)
            ->exists();
        if ($protectedUser) {
            SecurityLog::warning('IMPERSONATION_DENIED_PROTECTED', [
                'actor_id' => $actor->id,
                'target_klien_id' => $klienId,
                'reason' => 'Target klien belongs to protected role',
            ]);
            return ['success' => false, 'message' => 'Tidak dapat impersonasi akun owner/admin.'];
        }

        // Find the client user to get plan fields
        $clientUser = User::where('klien_id', $klienId)
            ->where('role', 'umkm')
            ->first();

        // Store in session
        Session::put(self::SESSION_KEY_KLIEN_ID, $klienId);
        Session::put(self::SESSION_KEY_META, [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'klien_id' => $klienId,
            'klien_nama' => $klien->nama_perusahaan ?? 'Unknown',
            'client_user_id' => $clientUser?->id,
            'started_at' => now()->toIso8601String(),
        ]);

        SecurityLog::info('IMPERSONATION_STARTED', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'actor_role' => $actor->role,
            'target_klien_id' => $klienId,
            'target_klien_nama' => $klien->nama_perusahaan,
            'client_user_id' => $clientUser?->id,
        ]);

        Log::info('[ClientContextService] Impersonation started', [
            'actor' => $actor->id,
            'klien_id' => $klienId,
        ]);

        return [
            'success' => true,
            'message' => "Sekarang melihat sebagai: {$klien->nama_perusahaan}",
            'klien' => $klien,
        ];
    }

    /**
     * Stop current impersonation.
     * 
     * @param User|null $actor The user stopping impersonation
     * @return array{success: bool, message: string}
     */
    public function stopImpersonation(?User $actor = null): array
    {
        $meta = Session::get(self::SESSION_KEY_META, []);
        $klienId = Session::get(self::SESSION_KEY_KLIEN_ID);

        if (!$klienId) {
            return ['success' => false, 'message' => 'Tidak sedang dalam mode impersonasi.'];
        }

        // Clear session
        Session::forget(self::SESSION_KEY_KLIEN_ID);
        Session::forget(self::SESSION_KEY_META);

        SecurityLog::info('IMPERSONATION_STOPPED', [
            'actor_id' => $actor?->id ?? ($meta['actor_id'] ?? null),
            'klien_id' => $klienId,
            'klien_nama' => $meta['klien_nama'] ?? 'Unknown',
            'duration_seconds' => isset($meta['started_at']) 
                ? now()->diffInSeconds(\Carbon\Carbon::parse($meta['started_at'])) 
                : null,
        ]);

        Log::info('[ClientContextService] Impersonation stopped', [
            'actor' => $actor?->id,
            'klien_id' => $klienId,
        ]);

        return ['success' => true, 'message' => 'Mode impersonasi diakhiri.'];
    }

    /**
     * Check if currently impersonating.
     */
    public function isImpersonating(): bool
    {
        return Session::has(self::SESSION_KEY_KLIEN_ID);
    }

    /**
     * Get the impersonated Klien ID.
     */
    public function getImpersonatedKlienId(): ?int
    {
        return Session::get(self::SESSION_KEY_KLIEN_ID);
    }

    /**
     * Get impersonation metadata.
     */
    public function getImpersonationMeta(): array
    {
        return Session::get(self::SESSION_KEY_META, []);
    }

    /**
     * Get the impersonated Klien model.
     */
    public function getImpersonatedKlien(): ?Klien
    {
        $klienId = $this->getImpersonatedKlienId();
        return $klienId ? Klien::find($klienId) : null;
    }

    /**
     * Get the client User record for the impersonated Klien.
     * Returns the primary umkm user for this klien.
     */
    public function getClientUser(): ?User
    {
        $meta = $this->getImpersonationMeta();
        $clientUserId = $meta['client_user_id'] ?? null;
        
        if ($clientUserId) {
            return User::find($clientUserId);
        }

        // Fallback: query by klien_id
        $klienId = $this->getImpersonatedKlienId();
        if ($klienId) {
            return User::where('klien_id', $klienId)
                ->where('role', 'umkm')
                ->first();
        }

        return null;
    }

    /**
     * Apply impersonation overrides to the authenticated user.
     * Called by ImpersonateClient middleware early in the pipeline.
     * 
     * This sets attributes on the User model so ALL downstream code
     * transparently sees the client's klien_id, plan, etc.
     */
    public function applyImpersonation(User $user): void
    {
        if (!$this->isImpersonating()) {
            return;
        }

        if (!in_array($user->role, self::ALLOWED_ROLES, true)) {
            // Non-owner has impersonation session data — clear it (safety)
            $this->stopImpersonation($user);
            return;
        }

        $clientUser = $this->getClientUser();
        if (!$clientUser) {
            // Client user no longer exists — clear impersonation
            Log::warning('[ClientContextService] Client user not found, clearing impersonation', [
                'klien_id' => $this->getImpersonatedKlienId(),
            ]);
            $this->stopImpersonation($user);
            return;
        }

        // Apply impersonation overrides on User model
        $user->startImpersonation($clientUser);
    }
}

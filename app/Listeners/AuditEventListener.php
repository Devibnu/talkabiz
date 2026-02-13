<?php

namespace App\Listeners;

use App\Services\AuditLogService;
use App\Models\AuditLog;
use App\Models\AccessLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;

/**
 * AuditEventListener - Auto-log Critical Events
 * 
 * Purpose:
 * - Automatically log authentication events
 * - Track security-relevant activities
 * - Provide audit trail for compliance
 * 
 * Events:
 * - Login/Logout
 * - Failed login attempts
 * - Password resets
 * - User registration
 * - Email verification
 * - Account lockout
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class AuditEventListener
{
    protected AuditLogService $auditService;
    
    public function __construct(AuditLogService $auditService)
    {
        $this->auditService = $auditService;
    }
    
    /**
     * Subscribe to multiple events
     */
    public function subscribe($events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailedLogin',
            PasswordReset::class => 'handlePasswordReset',
            Registered::class => 'handleRegistered',
            Verified::class => 'handleVerified',
            Lockout::class => 'handleLockout',
        ];
    }
    
    /**
     * Handle successful login
     */
    public function handleLogin(Login $event): void
    {
        try {
            $user = $event->user;
            
            $this->auditService->log('login_success', 'auth', $user->id, [
                'actor_type' => $this->getActorType($user),
                'actor_id' => $user->id,
                'actor_email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
                'category' => AuditLog::CATEGORY_AUTH,
                'context' => [
                    'guard' => $event->guard,
                    'remember' => $event->remember,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log login event', [
                'error' => $e->getMessage(),
                'user_id' => $event->user->id ?? null,
            ]);
        }
    }
    
    /**
     * Handle logout
     */
    public function handleLogout(Logout $event): void
    {
        try {
            $user = $event->user;
            
            if (!$user) {
                return;
            }
            
            $this->auditService->log('logout', 'auth', $user->id, [
                'actor_type' => $this->getActorType($user),
                'actor_id' => $user->id,
                'actor_email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
                'category' => AuditLog::CATEGORY_AUTH,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log logout event', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle failed login attempt
     */
    public function handleFailedLogin(Failed $event): void
    {
        try {
            $this->auditService->log('login_failed', 'auth', null, [
                'actor_type' => AuditLog::ACTOR_USER,
                'actor_email' => $event->credentials['email'] ?? null,
                'category' => AuditLog::CATEGORY_AUTH,
                'status' => 'failed',
                'failure_reason' => 'Invalid credentials',
                'context' => [
                    'guard' => $event->guard,
                    'attempted_email' => $event->credentials['email'] ?? null,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log failed login event', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle password reset
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        try {
            $user = $event->user;
            
            $this->auditService->log('password_reset', 'auth', $user->id, [
                'actor_type' => $this->getActorType($user),
                'actor_id' => $user->id,
                'actor_email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
                'category' => AuditLog::CATEGORY_AUTH,
                'classification' => AuditLog::CLASS_CONFIDENTIAL,
                'description' => 'User password was reset',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log password reset event', [
                'error' => $e->getMessage(),
                'user_id' => $event->user->id ?? null,
            ]);
        }
    }
    
    /**
     * Handle new user registration
     */
    public function handleRegistered(Registered $event): void
    {
        try {
            $user = $event->user;
            
            $this->auditService->log('user_registered', 'user', $user->id, [
                'actor_type' => AuditLog::ACTOR_USER,
                'actor_id' => $user->id,
                'actor_email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
                'category' => AuditLog::CATEGORY_CORE,
                'new_values' => [
                    'email' => $user->email,
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
                'description' => 'New user registered',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log registration event', [
                'error' => $e->getMessage(),
                'user_id' => $event->user->id ?? null,
            ]);
        }
    }
    
    /**
     * Handle email verification
     */
    public function handleVerified(Verified $event): void
    {
        try {
            $user = $event->user;
            
            $this->auditService->log('email_verified', 'user', $user->id, [
                'actor_type' => $this->getActorType($user),
                'actor_id' => $user->id,
                'actor_email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
                'category' => AuditLog::CATEGORY_AUTH,
                'description' => 'User email verified',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log email verification event', [
                'error' => $e->getMessage(),
                'user_id' => $event->user->id ?? null,
            ]);
        }
    }
    
    /**
     * Handle account lockout
     */
    public function handleLockout(Lockout $event): void
    {
        try {
            $request = $event->request;
            $email = $request->input('email');
            
            $this->auditService->log('account_lockout', 'auth', null, [
                'actor_type' => AuditLog::ACTOR_SYSTEM,
                'actor_email' => $email,
                'category' => AuditLog::CATEGORY_AUTH,
                'status' => 'failed',
                'failure_reason' => 'Too many login attempts',
                'classification' => AuditLog::CLASS_CONFIDENTIAL,
                'context' => [
                    'attempted_email' => $email,
                    'ip' => $request->ip(),
                ],
                'description' => 'Account locked due to too many failed attempts',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to log lockout event', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Determine actor type based on user role
     */
    protected function getActorType($user): string
    {
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return AuditLog::ACTOR_ADMIN;
        }
        
        if (isset($user->role) && $user->role === 'admin') {
            return AuditLog::ACTOR_ADMIN;
        }
        
        return AuditLog::ACTOR_USER;
    }
}

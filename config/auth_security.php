<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login Security Configuration
    |--------------------------------------------------------------------------
    |
    | Semua nilai terkait brute-force protection, account locking,
    | dan unlock policy dikonfigurasi di sini. TIDAK ADA hardcode di controller.
    |
    */

    // ==================== PROGRESSIVE LOCKOUT ====================

    // Jumlah gagal login sebelum CAPTCHA ditampilkan
    'captcha_threshold' => (int) env('LOGIN_CAPTCHA_THRESHOLD', 5),

    // Jumlah gagal login sebelum lock TIER 1 (15 menit)
    'lock_tier1_threshold' => (int) env('LOGIN_LOCK_TIER1_THRESHOLD', 10),

    // Durasi lock TIER 1 dalam detik (default: 15 menit = 900)
    'lock_tier1_seconds' => (int) env('LOGIN_LOCK_TIER1_SECONDS', 900),

    // Jumlah gagal login sebelum lock TIER 2 (1 jam)
    'lock_tier2_threshold' => (int) env('LOGIN_LOCK_TIER2_THRESHOLD', 20),

    // Durasi lock TIER 2 dalam detik (default: 1 jam = 3600)
    'lock_tier2_seconds' => (int) env('LOGIN_LOCK_TIER2_SECONDS', 3600),

    // ==================== OWNER PROTECTION ====================

    // Owner/Admin: max lock duration dalam detik (default: 10 menit)
    'owner_max_lock_seconds' => (int) env('LOGIN_OWNER_MAX_LOCK_SECONDS', 600),

    // Roles yang dianggap "owner" (tidak di-lock keras)
    'owner_roles' => ['owner', 'super_admin', 'admin', 'superadmin'],

    // ==================== RATE LIMITER ====================

    // Max requests per menit per IP
    'rate_limit_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 10),

    // Rate limiter decay dalam detik
    'rate_limit_decay_seconds' => (int) env('LOGIN_RATE_LIMIT_DECAY', 60),

    // ==================== UNLOCK VIA EMAIL ====================

    // Berapa lama unlock token valid (dalam menit)
    'unlock_token_expiry_minutes' => (int) env('LOGIN_UNLOCK_TOKEN_EXPIRY', 30),

    // ==================== AUDIT ====================

    // Log semua login events
    'audit_enabled' => (bool) env('LOGIN_AUDIT_ENABLED', true),

];

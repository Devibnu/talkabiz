<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate Limit & Throttling Tables
 * 
 * STRATEGI THROTTLING MULTI-LAYER:
 * ================================
 * 
 * 1. GLOBAL SYSTEM LIMIT
 *    - Melindungi seluruh platform dari overload
 *    - Contoh: Max 10,000 pesan/menit total
 * 
 * 2. PER SENDER (NOMOR WA) LIMIT
 *    - Melindungi nomor WA dari ban
 *    - WhatsApp policy: ~80 pesan/menit untuk nomor verified
 *    - Nomor baru: ~20 pesan/menit (warm-up period)
 * 
 * 3. PER USER/KLIEN LIMIT
 *    - Berdasarkan plan (UMKM vs Corporate)
 *    - Mencegah 1 user memonopoli resources
 * 
 * 4. PER CAMPAIGN LIMIT
 *    - Throttling campaign besar
 *    - Spread load over time
 * 
 * KENAPA THROTTLING WAJIB:
 * ========================
 * 1. WhatsApp Policy Compliance
 *    - Spam detection triggers BAN
 *    - Sudden spike = suspicious activity
 * 
 * 2. Deliverability
 *    - Steady rate = better delivery
 *    - Flood = message drops
 * 
 * 3. System Stability
 *    - Prevent queue overflow
 *    - Predictable resource usage
 * 
 * 4. Fair Usage
 *    - Semua user dapat share yang adil
 *    - Mencegah abuse
 * 
 * @author Senior Software Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== RATE LIMIT BUCKETS ====================
        /**
         * Token Bucket storage untuk rate limiting
         * 
         * Token Bucket Algorithm:
         * - Bucket punya kapasitas (max_tokens)
         * - Token ditambahkan dengan rate tertentu (refill_rate)
         * - Setiap request consume token
         * - Jika token habis, request ditolak/delay
         * 
         * Ini lebih fair dari fixed window karena:
         * - Allows burst (sampai max_tokens)
         * - Steady state = refill_rate
         */
        Schema::create('rate_limit_buckets', function (Blueprint $table) {
            $table->id();
            
            /**
             * Bucket identifier
             * Format: {scope}:{id}
             * Contoh:
             * - global:system
             * - sender:628123456789
             * - klien:123
             * - campaign:456
             */
            $table->string('bucket_key', 128)->unique();
            
            /**
             * Bucket type untuk grouping
             */
            $table->enum('bucket_type', [
                'global',    // System-wide limit
                'sender',    // Per nomor WA sender
                'klien',     // Per client/user
                'campaign',  // Per campaign
            ]);
            
            /**
             * Reference ID (klien_id, campaign_id, etc)
             */
            $table->unsignedBigInteger('reference_id')->nullable();
            
            /**
             * Current token count
             */
            $table->decimal('tokens', 10, 2)->default(0);
            
            /**
             * Maximum tokens (burst limit)
             */
            $table->unsignedInteger('max_tokens')->default(100);
            
            /**
             * Token refill rate per second
             */
            $table->decimal('refill_rate', 8, 4)->default(1.0);
            
            /**
             * Last refill timestamp (for calculation)
             */
            $table->timestamp('last_refill_at')->nullable();
            
            /**
             * Bucket configuration (JSON)
             * Untuk override settings per bucket
             */
            $table->json('config')->nullable();
            
            /**
             * Is bucket currently limited/throttled?
             */
            $table->boolean('is_limited')->default(false);
            
            /**
             * When will limit be lifted?
             */
            $table->timestamp('limited_until')->nullable();
            
            /**
             * Reason for limiting (if any)
             */
            $table->string('limit_reason')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('bucket_type');
            $table->index('reference_id');
            $table->index(['bucket_type', 'reference_id']);
            $table->index('is_limited');
        });

        // ==================== RATE LIMIT TIERS ====================
        /**
         * Definisi tier rate limit berdasarkan segment
         * Ini adalah "aturan" untuk setiap tier
         */
        Schema::create('rate_limit_tiers', function (Blueprint $table) {
            $table->id();
            
            /**
             * Tier code
             */
            $table->string('code', 50)->unique();
            
            /**
             * Tier name
             */
            $table->string('name', 100);
            
            /**
             * Segment: umkm, corporate, enterprise
             */
            $table->string('segment', 50)->default('umkm');
            
            // ==================== LIMITS ====================
            
            /**
             * Messages per minute (steady state)
             */
            $table->unsignedInteger('messages_per_minute')->default(30);
            
            /**
             * Messages per hour
             */
            $table->unsignedInteger('messages_per_hour')->default(500);
            
            /**
             * Messages per day
             */
            $table->unsignedInteger('messages_per_day')->default(5000);
            
            /**
             * Burst limit (max tokens in bucket)
             */
            $table->unsignedInteger('burst_limit')->default(50);
            
            /**
             * Max concurrent campaigns
             */
            $table->unsignedTinyInteger('max_concurrent_campaigns')->default(3);
            
            /**
             * Max campaign size (targets per campaign)
             */
            $table->unsignedInteger('max_campaign_size')->default(1000);
            
            /**
             * Inter-message delay (milliseconds)
             * Minimum delay between messages
             */
            $table->unsignedInteger('inter_message_delay_ms')->default(2000);
            
            /**
             * Sender warm-up period (days)
             * Nomor baru punya limit lebih rendah
             */
            $table->unsignedTinyInteger('sender_warmup_days')->default(7);
            
            /**
             * Warm-up rate multiplier (0.1 - 1.0)
             * Selama warm-up, rate = normal_rate * multiplier
             */
            $table->decimal('warmup_rate_multiplier', 3, 2)->default(0.25);
            
            /**
             * Priority dalam queue (higher = more priority)
             */
            $table->unsignedTinyInteger('queue_priority')->default(5);
            
            /**
             * Features (JSON)
             */
            $table->json('features')->nullable();
            
            /**
             * Is active?
             */
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Index
            $table->index('segment');
            $table->index('is_active');
        });

        // ==================== SENDER STATUS ====================
        /**
         * Status nomor WA sender
         * Untuk tracking health dan warm-up period
         */
        Schema::create('sender_status', function (Blueprint $table) {
            $table->id();
            
            /**
             * Klien owner
             */
            $table->unsignedBigInteger('klien_id');
            
            /**
             * Nomor WA sender (normalized: 628xxx)
             */
            $table->string('phone_number', 20);
            
            /**
             * Status sender
             */
            $table->enum('status', [
                'active',      // Normal, bisa kirim
                'warming_up',  // Dalam masa warm-up
                'limited',     // Kena rate limit
                'paused',      // Dipause manual
                'banned',      // Terdeteksi kena ban
                'inactive',    // Tidak aktif
            ])->default('warming_up');
            
            /**
             * Kapan sender mulai digunakan (untuk warm-up calculation)
             */
            $table->timestamp('started_at')->nullable();
            
            /**
             * Kapan warm-up selesai
             */
            $table->timestamp('warmup_ends_at')->nullable();
            
            /**
             * Total pesan terkirim (lifetime)
             */
            $table->unsignedBigInteger('total_sent')->default(0);
            
            /**
             * Total gagal (lifetime)
             */
            $table->unsignedBigInteger('total_failed')->default(0);
            
            /**
             * Pesan hari ini
             */
            $table->unsignedInteger('sent_today')->default(0);
            
            /**
             * Reset counter date
             */
            $table->date('counter_date')->nullable();
            
            /**
             * Health score (0-100)
             * Berdasarkan success rate
             */
            $table->unsignedTinyInteger('health_score')->default(100);
            
            /**
             * Last error (jika ada)
             */
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            
            /**
             * Error count today
             */
            $table->unsignedInteger('error_count_today')->default(0);
            
            /**
             * Consecutive errors (untuk circuit breaker)
             */
            $table->unsignedTinyInteger('consecutive_errors')->default(0);
            
            /**
             * Paused until (jika status = limited)
             */
            $table->timestamp('paused_until')->nullable();
            
            /**
             * Pause reason
             */
            $table->string('pause_reason')->nullable();
            
            /**
             * Metadata
             */
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('klien_id');
            $table->unique(['klien_id', 'phone_number']);
            $table->index('status');
            $table->index('health_score');
            $table->index('counter_date');
        });

        // ==================== THROTTLE EVENTS LOG ====================
        /**
         * Log throttle events untuk audit
         */
        Schema::create('throttle_events', function (Blueprint $table) {
            $table->id();
            
            /**
             * Event type
             */
            $table->enum('event_type', [
                'rate_limited',      // Request kena rate limit
                'bucket_empty',      // Token bucket kosong
                'sender_paused',     // Sender di-pause
                'campaign_throttled',// Campaign di-throttle
                'backoff_applied',   // Backoff applied
                'limit_recovered',   // Limit recovered
                'sender_banned',     // Sender terdeteksi ban
                'circuit_break',     // Circuit breaker triggered
            ]);
            
            /**
             * Related entities
             */
            $table->unsignedBigInteger('klien_id')->nullable();
            $table->unsignedBigInteger('kampanye_id')->nullable();
            $table->string('sender_phone', 20)->nullable();
            $table->string('bucket_key', 128)->nullable();
            
            /**
             * Detail
             */
            $table->string('reason')->nullable();
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->decimal('tokens_requested', 10, 2)->nullable();
            $table->decimal('tokens_available', 10, 2)->nullable();
            
            /**
             * Metadata
             */
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes untuk query
            $table->index('event_type');
            $table->index('klien_id');
            $table->index('kampanye_id');
            $table->index('sender_phone');
            $table->index('created_at');
            $table->index(['klien_id', 'event_type', 'created_at']);
        });

        // ==================== INSERT DEFAULT TIERS ====================
        $this->seedDefaultTiers();
    }

    /**
     * Seed default rate limit tiers
     */
    protected function seedDefaultTiers(): void
    {
        $tiers = [
            // UMKM Tiers
            [
                'code' => 'umkm_starter',
                'name' => 'UMKM Starter',
                'segment' => 'umkm',
                'messages_per_minute' => 20,
                'messages_per_hour' => 300,
                'messages_per_day' => 2000,
                'burst_limit' => 30,
                'max_concurrent_campaigns' => 2,
                'max_campaign_size' => 500,
                'inter_message_delay_ms' => 3000,
                'sender_warmup_days' => 14,
                'warmup_rate_multiplier' => 0.20,
                'queue_priority' => 3,
            ],
            [
                'code' => 'umkm_basic',
                'name' => 'UMKM Basic',
                'segment' => 'umkm',
                'messages_per_minute' => 30,
                'messages_per_hour' => 500,
                'messages_per_day' => 5000,
                'burst_limit' => 50,
                'max_concurrent_campaigns' => 3,
                'max_campaign_size' => 1000,
                'inter_message_delay_ms' => 2000,
                'sender_warmup_days' => 10,
                'warmup_rate_multiplier' => 0.25,
                'queue_priority' => 5,
            ],
            [
                'code' => 'umkm_pro',
                'name' => 'UMKM Pro',
                'segment' => 'umkm',
                'messages_per_minute' => 50,
                'messages_per_hour' => 1000,
                'messages_per_day' => 10000,
                'burst_limit' => 80,
                'max_concurrent_campaigns' => 5,
                'max_campaign_size' => 3000,
                'inter_message_delay_ms' => 1500,
                'sender_warmup_days' => 7,
                'warmup_rate_multiplier' => 0.30,
                'queue_priority' => 7,
            ],
            
            // Corporate Tiers
            [
                'code' => 'corporate_standard',
                'name' => 'Corporate Standard',
                'segment' => 'corporate',
                'messages_per_minute' => 80,
                'messages_per_hour' => 2000,
                'messages_per_day' => 20000,
                'burst_limit' => 100,
                'max_concurrent_campaigns' => 10,
                'max_campaign_size' => 10000,
                'inter_message_delay_ms' => 1000,
                'sender_warmup_days' => 5,
                'warmup_rate_multiplier' => 0.40,
                'queue_priority' => 8,
            ],
            [
                'code' => 'corporate_enterprise',
                'name' => 'Corporate Enterprise',
                'segment' => 'corporate',
                'messages_per_minute' => 120,
                'messages_per_hour' => 5000,
                'messages_per_day' => 50000,
                'burst_limit' => 150,
                'max_concurrent_campaigns' => 20,
                'max_campaign_size' => 50000,
                'inter_message_delay_ms' => 500,
                'sender_warmup_days' => 3,
                'warmup_rate_multiplier' => 0.50,
                'queue_priority' => 10,
            ],
        ];

        foreach ($tiers as $tier) {
            \Illuminate\Support\Facades\DB::table('rate_limit_tiers')->insert(array_merge($tier, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('throttle_events');
        Schema::dropIfExists('sender_status');
        Schema::dropIfExists('rate_limit_tiers');
        Schema::dropIfExists('rate_limit_buckets');
    }
};

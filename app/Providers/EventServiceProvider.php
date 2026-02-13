<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;

// Inbox Events
use App\Events\Inbox\PesanMasukEvent;
use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Events\Inbox\PesanDibacaEvent;

// Inbox Listeners
use App\Listeners\Inbox\KirimNotifikasiPesanMasuk;
use App\Listeners\Inbox\UpdateBadgeCounterInbox;
use App\Listeners\Inbox\CatatLogInbox;
use App\Listeners\Inbox\NotifikasiAssignment;

// Quota Listeners
use App\Listeners\QuotaFailedJobListener;

// Billing Events & Listeners
use App\Events\MessageStatusUpdated;
use App\Listeners\ProcessBillingForMessage;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        // =====================================================
        // AUTH EVENTS
        // =====================================================
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // =====================================================
        // INBOX EVENTS
        // =====================================================
        
        /**
         * PesanMasukEvent
         * Dipicu ketika ada pesan masuk dari customer
         */
        PesanMasukEvent::class => [
            KirimNotifikasiPesanMasuk::class,
            [UpdateBadgeCounterInbox::class, 'handlePesanMasuk'],
            [CatatLogInbox::class, 'handlePesanMasuk'],
        ],

        /**
         * PercakapanDiambilEvent
         * Dipicu ketika sales mengambil (assign) percakapan
         */
        PercakapanDiambilEvent::class => [
            [NotifikasiAssignment::class, 'handlePercakapanDiambil'],
            [UpdateBadgeCounterInbox::class, 'handlePercakapanDiambil'],
            [CatatLogInbox::class, 'handlePercakapanDiambil'],
        ],

        /**
         * PercakapanDilepasEvent
         * Dipicu ketika sales melepas (unassign) percakapan
         */
        PercakapanDilepasEvent::class => [
            [NotifikasiAssignment::class, 'handlePercakapanDilepas'],
            [UpdateBadgeCounterInbox::class, 'handlePercakapanDilepas'],
            [CatatLogInbox::class, 'handlePercakapanDilepas'],
        ],

        /**
         * PesanDibacaEvent
         * Dipicu ketika sales membaca pesan
         */
        PesanDibacaEvent::class => [
            [UpdateBadgeCounterInbox::class, 'handlePesanDibaca'],
            [CatatLogInbox::class, 'handlePesanDibaca'],
        ],

        // =====================================================
        // QUEUE EVENTS - Quota Safety Net
        // =====================================================
        
        /**
         * JobFailed
         * Auto-rollback quota when message job fails
         */
        JobFailed::class => [
            QuotaFailedJobListener::class,
        ],

        // =====================================================
        // BILLING EVENTS - Real-time Cost Tracking
        // =====================================================
        
        /**
         * MessageStatusUpdated
         * Calculate billing when message status changes (delivered/read)
         */
        MessageStatusUpdated::class => [
            ProcessBillingForMessage::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

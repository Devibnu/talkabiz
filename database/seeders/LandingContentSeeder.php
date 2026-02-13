<?php

namespace Database\Seeders;

use App\Models\LandingItem;
use App\Models\LandingSection;
use Illuminate\Database\Seeder;

/**
 * LandingContentSeeder
 * 
 * SINGLE SOURCE OF TRUTH untuk konten landing page.
 * Owner Panel = WRITE | Public Landing = READ ONLY
 */
class LandingContentSeeder extends Seeder
{
    public function run(): void
    {
        // ===================================
        // 1. HERO SECTION (order: 10)
        // ===================================
        $hero = LandingSection::firstOrCreate(
            ['key' => 'hero'],
            [
                'title' => 'Tingkatkan Penjualan dengan WhatsApp Marketing',
                'subtitle' => '#1 Platform WhatsApp Marketing di Indonesia',
                'is_active' => true,
                'order' => 10,
            ]
        );

        $heroItems = [
            [
                'section_id' => $hero->id,
                'key' => 'badge',
                'title' => '#1 Platform WhatsApp Marketing',
                'description' => null,
                'icon' => 'fas fa-rocket',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'description',
                'title' => null,
                'description' => 'Kirim broadcast promo, follow-up otomatis, dan kelola pelanggan dalam satu platform. Tingkatkan conversion rate hingga 10x lipat!',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'cta-primary',
                'title' => null,
                'description' => null,
                'icon' => null,
                'bullets' => null,
                'cta_label' => 'Coba Gratis Sekarang',
                'cta_url' => '/register',
                'is_active' => true,
                'order' => 3,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'cta-secondary',
                'title' => null,
                'description' => null,
                'icon' => null,
                'bullets' => null,
                'cta_label' => 'Lihat Demo',
                'cta_url' => '#fitur',
                'is_active' => true,
                'order' => 4,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'stat-clients',
                'title' => '5,000+',
                'description' => 'Bisnis Aktif',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 5,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'stat-messages',
                'title' => '10M+',
                'description' => 'Pesan Terkirim',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 6,
            ],
            [
                'section_id' => $hero->id,
                'key' => 'stat-conversion',
                'title' => '10x',
                'description' => 'Conversion Rate',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 7,
            ],
        ];

        foreach ($heroItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }

        // ===================================
        // 2. FEATURES SECTION (order: 15)
        // ===================================
        $features = LandingSection::firstOrCreate(
            ['key' => 'features'],
            [
                'title' => 'Fitur Lengkap untuk WhatsApp Marketing',
                'subtitle' => 'Semua yang Anda butuhkan dalam satu platform',
                'is_active' => true,
                'order' => 15,
            ]
        );

        $featureItems = [
            [
                'section_id' => $features->id,
                'key' => 'broadcast',
                'title' => 'Broadcast Massal',
                'description' => 'Kirim pesan promo ke ribuan kontak sekaligus dengan personalisasi nama dan variabel custom.',
                'icon' => 'fas fa-megaphone',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $features->id,
                'key' => 'autoresponse',
                'title' => 'Auto Response',
                'description' => 'Balas pesan pelanggan otomatis 24/7 dengan chatbot cerdas berbasis keyword.',
                'icon' => 'fas fa-robot',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'section_id' => $features->id,
                'key' => 'contacts',
                'title' => 'Contact Management',
                'description' => 'Kelola database kontak dengan label, segmentasi, dan custom fields untuk targeting tepat.',
                'icon' => 'fas fa-address-book',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 3,
            ],
            [
                'section_id' => $features->id,
                'key' => 'analytics',
                'title' => 'Analytics & Reports',
                'description' => 'Lacak performa campaign dengan laporan lengkap: delivery rate, read rate, dan click rate.',
                'icon' => 'fas fa-chart-line',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 4,
            ],
            [
                'section_id' => $features->id,
                'key' => 'templates',
                'title' => 'Message Templates',
                'description' => 'Simpan template pesan untuk digunakan berulang kali dan tingkatkan efisiensi tim.',
                'icon' => 'fas fa-file-alt',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 5,
            ],
            [
                'section_id' => $features->id,
                'key' => 'multidevice',
                'title' => 'Multi-Device',
                'description' => 'Gunakan satu nomor WhatsApp di banyak perangkat untuk kerja tim lebih efisien.',
                'icon' => 'fas fa-mobile-alt',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 6,
            ],
        ];

        foreach ($featureItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }

        // ===================================
        // 3. SOLUTIONS SECTION (order: 20)
        // ===================================
        $solutions = LandingSection::firstOrCreate(
            ['key' => 'solutions'],
            [
                'title' => 'Solusi untuk UMKM hingga Corporate',
                'subtitle' => 'Talkabiz dirancang fleksibel untuk berbagai skala bisnis',
                'is_active' => true,
                'order' => 20,
            ]
        );

        $solutionItems = [
            [
                'section_id' => $solutions->id,
                'key' => 'umkm',
                'title' => 'UMKM & Online Shop',
                'description' => 'Tingkatkan penjualan dengan broadcast promo langsung ke pelanggan setia Anda.',
                'icon' => 'fas fa-store',
                'bullets' => [
                    'Broadcast promo & diskon',
                    'Follow-up orderan otomatis',
                    'Notifikasi pengiriman',
                    'Harga terjangkau mulai 99rb/bulan',
                ],
                'cta_label' => 'Daftar UMKM',
                'cta_url' => '/register',
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $solutions->id,
                'key' => 'corporate',
                'title' => 'Corporate & Enterprise',
                'description' => 'Solusi WhatsApp Marketing skala besar dengan SLA dan dedicated support.',
                'icon' => 'fas fa-building',
                'bullets' => [
                    'Volume tinggi tanpa batas',
                    'API Integration untuk sistem internal',
                    'Dedicated Account Manager',
                    'Custom SLA & Priority Support',
                ],
                'cta_label' => 'Hubungi Sales',
                'cta_url' => '#contact-sales', // URL diatur via Owner Panel → Branding → WA Sales (SSOT)
                'is_active' => true,
                'order' => 2,
            ],
        ];

        foreach ($solutionItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }

        // ===================================
        // 4. TRUST SECTION (order: 30)
        // ===================================
        $trust = LandingSection::firstOrCreate(
            ['key' => 'trust'],
            [
                'title' => 'Kenapa Pilih Talkabiz?',
                'subtitle' => 'Dipercaya ribuan bisnis di Indonesia',
                'is_active' => true,
                'order' => 30,
            ]
        );

        $trustItems = [
            [
                'section_id' => $trust->id,
                'key' => 'speed',
                'title' => 'Cepat & Stabil',
                'description' => 'Server berkualitas tinggi untuk pengiriman pesan tanpa delay.',
                'icon' => 'fas fa-tachometer-alt',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $trust->id,
                'key' => 'security',
                'title' => 'Aman & Terenkripsi',
                'description' => 'Data Anda terproteksi dengan enkripsi tingkat enterprise.',
                'icon' => 'fas fa-lock',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'section_id' => $trust->id,
                'key' => 'support',
                'title' => 'Support Responsif',
                'description' => 'Tim support siap membantu via chat dan telepon.',
                'icon' => 'fas fa-headset',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 3,
            ],
            [
                'section_id' => $trust->id,
                'key' => 'updates',
                'title' => 'Update Berkala',
                'description' => 'Fitur baru dan perbaikan rutin untuk pengalaman terbaik.',
                'icon' => 'fas fa-sync',
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 4,
            ],
        ];

        foreach ($trustItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }

        // ===================================
        // 5. FAQ SECTION (order: 40)
        // ===================================
        $faq = LandingSection::firstOrCreate(
            ['key' => 'faq'],
            [
                'title' => 'Frequently Asked Questions',
                'subtitle' => 'Pertanyaan yang sering ditanyakan',
                'is_active' => true,
                'order' => 40,
            ]
        );

        $faqItems = [
            [
                'section_id' => $faq->id,
                'key' => 'what-is',
                'title' => 'Apa itu Talkabiz?',
                'description' => 'Talkabiz adalah platform WhatsApp Marketing yang membantu bisnis mengirim broadcast promo, auto-response, dan mengelola kontak pelanggan dalam satu dashboard.',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $faq->id,
                'key' => 'how-to-start',
                'title' => 'Bagaimana cara mulai menggunakan Talkabiz?',
                'description' => 'Daftar gratis, scan QR code dengan WhatsApp Anda, lalu Anda bisa langsung kirim broadcast pertama dalam 5 menit!',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'section_id' => $faq->id,
                'key' => 'pricing',
                'title' => 'Berapa biaya berlangganan Talkabiz?',
                'description' => 'Kami punya paket mulai dari 99rb/bulan untuk UMKM hingga paket corporate custom. Semua paket sudah include fitur broadcast, auto-response, dan analytics.',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 3,
            ],
            [
                'section_id' => $faq->id,
                'key' => 'spam',
                'title' => 'Apakah WhatsApp saya bisa kena banned?',
                'description' => 'Talkabiz menggunakan teknologi anti-spam dengan jeda pengiriman otomatis. Selama Anda follow best practice (kirim ke kontak yang opt-in), akun Anda aman.',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 4,
            ],
            [
                'section_id' => $faq->id,
                'key' => 'support',
                'title' => 'Apakah ada training dan support?',
                'description' => 'Ya! Semua pelanggan mendapat akses ke panduan lengkap, video tutorial, dan support via WhatsApp. Paket Corporate mendapat dedicated account manager.',
                'icon' => null,
                'bullets' => null,
                'cta_label' => null,
                'cta_url' => null,
                'is_active' => true,
                'order' => 5,
            ],
        ];

        foreach ($faqItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }

        // ===================================
        // 6. CTA SECTION (order: 50)
        // ===================================
        $cta = LandingSection::firstOrCreate(
            ['key' => 'cta'],
            [
                'title' => 'Siap Tingkatkan Penjualan Anda?',
                'subtitle' => 'Mulai gratis hari ini, tidak perlu kartu kredit',
                'is_active' => true,
                'order' => 50,
            ]
        );

        $ctaItems = [
            [
                'section_id' => $cta->id,
                'key' => 'primary',
                'title' => null,
                'description' => null,
                'icon' => 'fas fa-rocket',
                'bullets' => null,
                'cta_label' => 'Mulai Gratis Sekarang',
                'cta_url' => '/register',
                'is_active' => true,
                'order' => 1,
            ],
            [
                'section_id' => $cta->id,
                'key' => 'secondary',
                'title' => null,
                'description' => null,
                'icon' => 'fab fa-whatsapp',
                'bullets' => null,
                'cta_label' => 'Hubungi Sales',
                'cta_url' => '#contact-sales', // URL diatur via Owner Panel → Branding → WA Sales (SSOT)
                'is_active' => true,
                'order' => 2,
            ],
        ];

        foreach ($ctaItems as $item) {
            LandingItem::updateOrCreate(
                ['section_id' => $item['section_id'], 'key' => $item['key']],
                $item
            );
        }
    }
}

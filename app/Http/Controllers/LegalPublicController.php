<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegalPublicController extends Controller
{
    public function privacyPolicy(): View
    {
        return $this->renderDocument(
            LegalDocument::TYPE_PRIVACY,
            'Kebijakan Privasi Talkabiz',
            $this->defaultPrivacyContent()
        );
    }

    public function termsOfService(): View
    {
        return $this->renderDocument(
            LegalDocument::TYPE_TOS,
            'Syarat dan Ketentuan Talkabiz',
            $this->defaultTermsContent()
        );
    }

    public function dataDeletionInstructions(): View
    {
        return view('legal.public', [
            'title' => 'Petunjuk Penghapusan Data Talkabiz',
            'content' => $this->defaultDataDeletionContent(),
            'contentFormat' => LegalDocument::FORMAT_HTML,
            'document' => null,
        ]);
    }

    public function dataDeletionCallback(Request $request): JsonResponse
    {
        return response()->json([
            'url' => route('legal.data-deletion-instructions'),
            'confirmation_code' => 'talkabiz-data-deletion',
            'status' => 'received',
            'reference' => $request->input('signed_request') ? sha1($request->input('signed_request')) : null,
        ]);
    }

    private function renderDocument(string $type, string $fallbackTitle, string $fallbackContent): View
    {
        $document = LegalDocument::query()
            ->active()
            ->where('type', $type)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        return view('legal.public', [
            'title' => $document?->title ?: $fallbackTitle,
            'content' => $document?->content ?: $fallbackContent,
            'contentFormat' => $document?->content_format ?: LegalDocument::FORMAT_HTML,
            'document' => $document,
        ]);
    }

    private function defaultPrivacyContent(): string
    {
        return <<<'HTML'
<p>Talkabiz menghargai privasi pengguna dan berkomitmen melindungi data pribadi yang diproses melalui platform kami.</p>
<h2>Informasi yang Kami Kumpulkan</h2>
<p>Kami dapat mengumpulkan informasi akun, data bisnis, data kontak pelanggan, metadata penggunaan aplikasi, dan informasi teknis yang diperlukan untuk menjalankan layanan WhatsApp Business dan fitur Talkabiz lainnya.</p>
<h2>Penggunaan Informasi</h2>
<p>Data digunakan untuk menyediakan layanan, mengelola akun, memproses komunikasi bisnis, meningkatkan keamanan, mencegah penyalahgunaan, dan memenuhi kewajiban hukum.</p>
<h2>Pembagian Data</h2>
<p>Kami hanya membagikan data kepada penyedia layanan dan mitra yang diperlukan untuk operasional layanan, termasuk Meta/WhatsApp, payment gateway, dan infrastruktur hosting, sesuai kebutuhan layanan.</p>
<h2>Penyimpanan dan Keamanan</h2>
<p>Kami menerapkan kontrol akses, enkripsi, pencatatan audit, dan langkah keamanan teknis serta organisasi yang wajar untuk melindungi data pengguna.</p>
<h2>Hak Pengguna</h2>
<p>Pengguna dapat menghubungi kami untuk meminta akses, koreksi, atau penghapusan data sesuai ketentuan yang berlaku.</p>
<h2>Kontak</h2>
<p>Untuk pertanyaan privasi, hubungi kami melalui email <a href="mailto:admin@jasaibnu.com">admin@jasaibnu.com</a>.</p>
HTML;
    }

    private function defaultTermsContent(): string
    {
        return <<<'HTML'
<p>Dengan menggunakan Talkabiz, Anda setuju untuk menggunakan layanan secara sah, tidak menyalahgunakan sistem, dan mematuhi kebijakan Meta/WhatsApp serta hukum yang berlaku.</p>
<h2>Penggunaan Layanan</h2>
<p>Anda bertanggung jawab atas akun, data, template pesan, nomor WhatsApp Business, dan aktivitas bisnis yang Anda hubungkan ke platform.</p>
<h2>Kepatuhan</h2>
<p>Anda wajib memastikan bahwa penggunaan layanan, isi pesan, dan data pelanggan mematuhi hukum perlindungan data, anti-spam, dan kebijakan WhatsApp Business.</p>
<h2>Pembayaran</h2>
<p>Layanan berbayar tunduk pada paket, biaya, siklus penagihan, dan kebijakan pembatasan yang berlaku pada akun Anda.</p>
<h2>Pembatasan dan Penangguhan</h2>
<p>Kami dapat membatasi, menangguhkan, atau menghentikan akses jika ditemukan pelanggaran, penyalahgunaan, risiko keamanan, atau kewajiban kepatuhan.</p>
<h2>Batasan Tanggung Jawab</h2>
<p>Layanan disediakan sebagaimana adanya sesuai batas maksimum yang diizinkan hukum. Pengguna bertanggung jawab atas keputusan bisnis dan komunikasi yang dikirim melalui platform.</p>
HTML;
    }

    private function defaultDataDeletionContent(): string
    {
        return <<<'HTML'
<p>Jika Anda ingin meminta penghapusan data yang terkait dengan aplikasi Talkabiz, kirim permintaan ke <a href="mailto:admin@jasaibnu.com">admin@jasaibnu.com</a> dengan subjek <strong>Permintaan Penghapusan Data</strong>.</p>
<p>Sertakan informasi identitas akun dan detail bisnis yang relevan agar kami dapat memverifikasi permintaan Anda. Permintaan yang valid akan diproses sesuai kewajiban hukum dan operasional layanan.</p>
HTML;
    }
}
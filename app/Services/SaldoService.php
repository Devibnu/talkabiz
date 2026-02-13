<?php

namespace App\Services;

use App\Models\DompetSaldo;
use App\Models\TransaksiSaldo;
use App\Models\Kampanye;
use App\Models\LogAktivitas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

/**
 * SaldoService
 * 
 * Service untuk mengelola SEMUA operasi saldo klien.
 * Prinsip ANTI-BONCOS: Saldo tidak boleh minus, semua tercatat.
 * 
 * PENTING:
 * - Tidak ada komponen lain yang boleh mengubah saldo langsung
 * - Semua operasi saldo HARUS melalui service ini
 * - Gunakan database transaction & row locking untuk concurrent safety
 */
class SaldoService
{
    /**
     * Ambil data saldo lengkap klien
     * 
     * @param int $klienId
     * @return array
     */
    public function ambilSaldo(int $klienId): array
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();

        if (!$dompet) {
            return [
                'sukses' => false,
                'error' => 'Dompet tidak ditemukan untuk klien ini',
            ];
        }

        return [
            'sukses' => true,
            'data' => [
                'saldo_tersedia' => $dompet->saldo_tersedia,
                'saldo_tertahan' => $dompet->saldo_tertahan,
                'saldo_total' => $dompet->saldo_total,
                'status' => $dompet->status_saldo,
                'batas_warning' => $dompet->batas_warning,
                'batas_minimum' => $dompet->batas_minimum,
                'total_topup' => $dompet->total_topup,
                'total_terpakai' => $dompet->total_terpakai,
                'terakhir_topup' => $dompet->terakhir_topup,
            ],
        ];
    }

    /**
     * Cek apakah saldo cukup untuk nominal tertentu
     * 
     * @param int $klienId
     * @param int $nominal Jumlah yang akan dicek
     * @return array
     */
    public function cekSaldoCukup(int $klienId, int $nominal): array
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();

        if (!$dompet) {
            return [
                'cukup' => false,
                'error' => 'Dompet tidak ditemukan',
                'saldo_tersedia' => 0,
                'nominal_dibutuhkan' => $nominal,
                'kekurangan' => $nominal,
            ];
        }

        $cukup = $dompet->saldo_tersedia >= $nominal;
        $kekurangan = $cukup ? 0 : ($nominal - $dompet->saldo_tersedia);

        return [
            'cukup' => $cukup,
            'saldo_tersedia' => $dompet->saldo_tersedia,
            'saldo_tertahan' => $dompet->saldo_tertahan,
            'nominal_dibutuhkan' => $nominal,
            'kekurangan' => $kekurangan,
            'sisa_setelah' => $cukup ? ($dompet->saldo_tersedia - $nominal) : 0,
            'status_saldo' => $dompet->status_saldo,
        ];
    }

    /**
     * Hold (tahan) saldo untuk campaign
     * 
     * Dipanggil SEBELUM campaign mulai mengirim pesan.
     * Saldo dipindah dari tersedia ke tertahan.
     * 
     * @param int $klienId
     * @param int $nominal
     * @param int $kampanyeId
     * @param int|null $penggunaId Siapa yang melakukan aksi
     * @return array
     * @throws Exception
     */
    public function holdSaldo(int $klienId, int $nominal, int $kampanyeId, ?int $penggunaId = null): array
    {
        // Validasi input
        if ($nominal <= 0) {
            return [
                'sukses' => false,
                'error' => 'Nominal harus lebih dari 0',
            ];
        }

        // Gunakan transaction dengan row locking untuk prevent race condition
        return DB::transaction(function () use ($klienId, $nominal, $kampanyeId, $penggunaId) {
            
            // Lock row dompet untuk prevent concurrent update
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new Exception('Dompet tidak ditemukan untuk klien ini');
            }

            // ANTI-BONCOS: Cek saldo cukup
            if ($dompet->saldo_tersedia < $nominal) {
                return [
                    'sukses' => false,
                    'error' => 'Saldo tidak mencukupi',
                    'saldo_tersedia' => $dompet->saldo_tersedia,
                    'nominal_dibutuhkan' => $nominal,
                    'kekurangan' => $nominal - $dompet->saldo_tersedia,
                ];
            }

            // Simpan saldo sebelum operasi
            $saldoSebelum = $dompet->saldo_tersedia;

            // Pindahkan saldo dari tersedia ke tertahan
            $dompet->saldo_tersedia -= $nominal;
            $dompet->saldo_tertahan += $nominal;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            // Ambil nama kampanye untuk keterangan
            $kampanye = Kampanye::find($kampanyeId);
            $namaKampanye = $kampanye?->nama_kampanye ?? 'Unknown';

            // Catat transaksi HOLD
            $transaksi = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi(),
                'dompet_id' => $dompet->id,
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'pengguna_id' => $penggunaId,
                'jenis' => 'hold',
                'nominal' => -$nominal, // Negatif karena keluar dari tersedia
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "Hold saldo untuk campaign: {$namaKampanye}",
                'referensi' => $kampanye?->kode_kampanye,
            ]);

            // Log aktivitas
            LogAktivitas::catat(
                'hold',
                'saldo',
                "Hold saldo Rp " . number_format($nominal, 0, ',', '.') . " untuk campaign {$namaKampanye}",
                $penggunaId,
                $klienId,
                'dompet_saldo',
                $dompet->id
            );

            return [
                'sukses' => true,
                'transaksi_id' => $transaksi->id,
                'kode_transaksi' => $transaksi->kode_transaksi,
                'nominal_dihold' => $nominal,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_tersedia' => $dompet->saldo_tersedia,
                'saldo_tertahan' => $dompet->saldo_tertahan,
                'status_saldo' => $dompet->status_saldo,
            ];
        });
    }

    /**
     * Potong saldo dari yang di-hold (setelah pesan terkirim)
     * 
     * Dipanggil SETELAH pesan berhasil terkirim.
     * Mengurangi saldo_tertahan dan menambah total_terpakai.
     * 
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $jumlahPesan Jumlah pesan yang terkirim
     * @param int $hargaPerPesan
     * @param int|null $penggunaId
     * @return array
     */
    public function potongSaldo(
        int $klienId, 
        int $kampanyeId, 
        int $jumlahPesan, 
        int $hargaPerPesan = 50,
        ?int $penggunaId = null
    ): array {
        // Validasi input
        if ($jumlahPesan <= 0) {
            return [
                'sukses' => false,
                'error' => 'Jumlah pesan harus lebih dari 0',
            ];
        }

        $nominal = $jumlahPesan * $hargaPerPesan;

        return DB::transaction(function () use ($klienId, $kampanyeId, $nominal, $jumlahPesan, $penggunaId) {
            
            // Lock row dompet
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new Exception('Dompet tidak ditemukan');
            }

            // ANTI-BONCOS: Cek saldo tertahan cukup
            // Jika tidak cukup (anomali), potong sebanyak yang ada
            $nominalPotong = min($nominal, $dompet->saldo_tertahan);
            
            if ($nominalPotong <= 0) {
                return [
                    'sukses' => false,
                    'error' => 'Tidak ada saldo tertahan untuk dipotong',
                    'saldo_tertahan' => $dompet->saldo_tertahan,
                ];
            }

            // Warning jika nominal potong berbeda (anomali)
            $isAnomali = $nominalPotong < $nominal;

            // Simpan saldo sebelum
            $saldoSebelum = $dompet->saldo_tersedia;
            $tertahanSebelum = $dompet->saldo_tertahan;

            // Potong dari saldo tertahan
            $dompet->saldo_tertahan -= $nominalPotong;
            $dompet->total_terpakai += $nominalPotong;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            // Ambil nama kampanye
            $kampanye = Kampanye::find($kampanyeId);
            $namaKampanye = $kampanye?->nama_kampanye ?? 'Unknown';

            // Catat transaksi POTONG
            $transaksi = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi(),
                'dompet_id' => $dompet->id,
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'pengguna_id' => $penggunaId,
                'jenis' => 'potong',
                'nominal' => -$nominalPotong,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "Pemotongan {$jumlahPesan} pesan campaign: {$namaKampanye}",
                'referensi' => $kampanye?->kode_kampanye,
            ]);

            // Update biaya aktual di campaign
            if ($kampanye) {
                $kampanye->increment('biaya_aktual', $nominalPotong);
                $kampanye->decrement('saldo_dihold', $nominalPotong);
            }

            // Log aktivitas
            LogAktivitas::catat(
                'potong',
                'saldo',
                "Potong saldo Rp " . number_format($nominalPotong, 0, ',', '.') . " ({$jumlahPesan} pesan) campaign {$namaKampanye}",
                $penggunaId,
                $klienId,
                'transaksi_saldo',
                $transaksi->id
            );

            return [
                'sukses' => true,
                'transaksi_id' => $transaksi->id,
                'nominal_dipotong' => $nominalPotong,
                'jumlah_pesan' => $jumlahPesan,
                'saldo_tertahan_sebelum' => $tertahanSebelum,
                'saldo_tertahan_sekarang' => $dompet->saldo_tertahan,
                'is_anomali' => $isAnomali,
                'warning' => $isAnomali ? 'Nominal potong disesuaikan karena hold tidak cukup' : null,
            ];
        });
    }

    /**
     * Lepas (release) saldo yang di-hold
     * 
     * Dipanggil jika:
     * - Campaign dibatalkan
     * - Pesan gagal kirim (refund)
     * - Campaign selesai dengan sisa hold
     * 
     * @param int $klienId
     * @param int $nominal
     * @param int $kampanyeId
     * @param string $alasan
     * @param int|null $penggunaId
     * @return array
     */
    public function lepasHold(
        int $klienId, 
        int $nominal, 
        int $kampanyeId, 
        string $alasan = 'Release saldo',
        ?int $penggunaId = null
    ): array {
        // Validasi input
        if ($nominal <= 0) {
            return [
                'sukses' => false,
                'error' => 'Nominal harus lebih dari 0',
            ];
        }

        return DB::transaction(function () use ($klienId, $nominal, $kampanyeId, $alasan, $penggunaId) {
            
            // Lock row dompet
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new Exception('Dompet tidak ditemukan');
            }

            // Release maksimal sebanyak yang di-hold
            $nominalRelease = min($nominal, $dompet->saldo_tertahan);

            if ($nominalRelease <= 0) {
                return [
                    'sukses' => false,
                    'error' => 'Tidak ada saldo tertahan untuk dilepas',
                    'saldo_tertahan' => $dompet->saldo_tertahan,
                ];
            }

            // Simpan saldo sebelum
            $saldoSebelum = $dompet->saldo_tersedia;

            // Pindahkan kembali dari tertahan ke tersedia
            $dompet->saldo_tertahan -= $nominalRelease;
            $dompet->saldo_tersedia += $nominalRelease;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            // Ambil nama kampanye
            $kampanye = Kampanye::find($kampanyeId);
            $namaKampanye = $kampanye?->nama_kampanye ?? 'Unknown';

            // Tentukan jenis transaksi
            $jenisTransaksi = str_contains(strtolower($alasan), 'gagal') ? 'refund' : 'release';

            // Catat transaksi RELEASE/REFUND
            $transaksi = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi(),
                'dompet_id' => $dompet->id,
                'klien_id' => $klienId,
                'kampanye_id' => $kampanyeId,
                'pengguna_id' => $penggunaId,
                'jenis' => $jenisTransaksi,
                'nominal' => +$nominalRelease, // Positif karena masuk ke tersedia
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "{$alasan} - Campaign: {$namaKampanye}",
                'referensi' => $kampanye?->kode_kampanye,
            ]);

            // Update saldo_dihold di campaign
            if ($kampanye) {
                $kampanye->decrement('saldo_dihold', $nominalRelease);
            }

            // Log aktivitas
            LogAktivitas::catat(
                $jenisTransaksi,
                'saldo',
                "Release saldo Rp " . number_format($nominalRelease, 0, ',', '.') . " - {$alasan}",
                $penggunaId,
                $klienId,
                'transaksi_saldo',
                $transaksi->id
            );

            return [
                'sukses' => true,
                'transaksi_id' => $transaksi->id,
                'jenis' => $jenisTransaksi,
                'nominal_dilepas' => $nominalRelease,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_tersedia' => $dompet->saldo_tersedia,
                'saldo_tertahan' => $dompet->saldo_tertahan,
            ];
        });
    }

    /**
     * Cek dan jalankan auto-stop jika saldo habis
     * 
     * Dipanggil SEBELUM mengirim setiap batch pesan.
     * Jika saldo hold tidak cukup, campaign akan di-pause.
     * 
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $jumlahPesanAkanDikirim
     * @param int $hargaPerPesan
     * @return array
     */
    public function autoStopJikaSaldoHabis(
        int $klienId, 
        int $kampanyeId, 
        int $jumlahPesanAkanDikirim,
        int $hargaPerPesan = 50
    ): array {
        $biayaBatch = $jumlahPesanAkanDikirim * $hargaPerPesan;

        // Ambil data campaign dengan lock
        return DB::transaction(function () use ($klienId, $kampanyeId, $biayaBatch, $jumlahPesanAkanDikirim, $hargaPerPesan) {
            
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->lockForUpdate()
                ->first();

            if (!$kampanye) {
                return [
                    'lanjut' => false,
                    'error' => 'Campaign tidak ditemukan',
                ];
            }

            // Cek status campaign
            if ($kampanye->status !== 'berjalan') {
                return [
                    'lanjut' => false,
                    'error' => 'Campaign tidak dalam status berjalan',
                    'status' => $kampanye->status,
                ];
            }

            $saldoHold = $kampanye->saldo_dihold;

            // KONDISI 1: Saldo hold sudah habis
            if ($saldoHold <= 0) {
                $this->pauseCampaignKarenaSaldoHabis($kampanye);
                
                return [
                    'lanjut' => false,
                    'harus_stop' => true,
                    'alasan' => 'Saldo yang ditahan sudah habis',
                    'saldo_dihold' => 0,
                    'status_campaign' => 'pause',
                ];
            }

            // KONDISI 2: Saldo hold tidak cukup untuk batch
            if ($saldoHold < $biayaBatch) {
                // Hitung berapa yang masih bisa dikirim
                $bisaDikirim = (int) floor($saldoHold / $hargaPerPesan);

                if ($bisaDikirim <= 0) {
                    $this->pauseCampaignKarenaSaldoHabis($kampanye);
                    
                    return [
                        'lanjut' => false,
                        'harus_stop' => true,
                        'alasan' => 'Saldo tidak cukup untuk melanjutkan',
                        'saldo_dihold' => $saldoHold,
                        'bisa_kirim' => 0,
                        'status_campaign' => 'pause',
                    ];
                }

                // Masih bisa kirim sebagian
                return [
                    'lanjut' => true,
                    'harus_stop_setelah_batch' => true,
                    'bisa_kirim' => $bisaDikirim,
                    'saldo_dihold' => $saldoHold,
                    'pesan' => "Hanya bisa kirim {$bisaDikirim} pesan, setelahnya akan pause",
                ];
            }

            // KONDISI 3: Saldo cukup, lanjutkan
            return [
                'lanjut' => true,
                'harus_stop' => false,
                'bisa_kirim' => $jumlahPesanAkanDikirim,
                'saldo_dihold' => $saldoHold,
                'sisa_setelah_batch' => $saldoHold - $biayaBatch,
            ];
        });
    }

    /**
     * Tambah saldo setelah top up disetujui
     * 
     * @param int $klienId
     * @param int $nominal
     * @param int $transaksiTopupId ID transaksi top up yang disetujui
     * @param int $adminId Admin yang menyetujui
     * @param string|null $catatan
     * @return array
     */
    public function tambahSaldo(
        int $klienId, 
        int $nominal, 
        int $transaksiTopupId,
        int $adminId,
        ?string $catatan = null
    ): array {
        if ($nominal <= 0) {
            return [
                'sukses' => false,
                'error' => 'Nominal harus lebih dari 0',
            ];
        }

        return DB::transaction(function () use ($klienId, $nominal, $transaksiTopupId, $adminId, $catatan) {
            
            // Lock dompet
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new Exception('Dompet tidak ditemukan');
            }

            // Lock transaksi top up
            $transaksiTopup = TransaksiSaldo::where('id', $transaksiTopupId)
                ->lockForUpdate()
                ->first();

            if (!$transaksiTopup) {
                throw new Exception('Transaksi top up tidak ditemukan');
            }

            // Cek status top up
            if ($transaksiTopup->status_topup !== 'pending') {
                return [
                    'sukses' => false,
                    'error' => 'Transaksi top up bukan pending',
                    'status_saat_ini' => $transaksiTopup->status_topup,
                ];
            }

            // Simpan saldo sebelum
            $saldoSebelum = $dompet->saldo_tersedia;

            // Tambah saldo
            $dompet->saldo_tersedia += $nominal;
            $dompet->total_topup += $nominal;
            $dompet->terakhir_topup = now();
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            // Update transaksi top up
            $transaksiTopup->update([
                'status_topup' => 'disetujui',
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'diproses_oleh' => $adminId,
                'waktu_diproses' => now(),
                'catatan_admin' => $catatan ?? 'Disetujui',
            ]);

            // Log aktivitas
            LogAktivitas::logApproveTopup(
                $transaksiTopupId,
                $klienId,
                $adminId,
                $nominal
            );

            return [
                'sukses' => true,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'nominal_ditambah' => $nominal,
                'status_saldo' => $dompet->status_saldo,
            ];
        });
    }

    /**
     * Tolak request top up
     * 
     * @param int $transaksiTopupId
     * @param int $adminId
     * @param string $alasan
     * @return array
     */
    public function tolakTopup(int $transaksiTopupId, int $adminId, string $alasan): array
    {
        return DB::transaction(function () use ($transaksiTopupId, $adminId, $alasan) {
            
            $transaksi = TransaksiSaldo::where('id', $transaksiTopupId)
                ->lockForUpdate()
                ->first();

            if (!$transaksi) {
                throw new Exception('Transaksi tidak ditemukan');
            }

            if ($transaksi->status_topup !== 'pending') {
                return [
                    'sukses' => false,
                    'error' => 'Transaksi bukan pending',
                ];
            }

            $transaksi->update([
                'status_topup' => 'ditolak',
                'diproses_oleh' => $adminId,
                'waktu_diproses' => now(),
                'catatan_admin' => $alasan,
            ]);

            // Log aktivitas
            LogAktivitas::catat(
                'reject',
                'saldo',
                "Menolak top up: {$alasan}",
                $adminId,
                $transaksi->klien_id,
                'transaksi_saldo',
                $transaksiTopupId
            );

            return [
                'sukses' => true,
                'pesan' => 'Top up berhasil ditolak',
            ];
        });
    }

    /**
     * Buat request top up baru
     * 
     * @param int $klienId
     * @param int $nominal
     * @param string $metodeBayar
     * @param string|null $bankTujuan
     * @param int|null $penggunaId
     * @return array
     */
    public function buatRequestTopup(
        int $klienId,
        int $nominal,
        string $metodeBayar,
        ?string $bankTujuan = null,
        ?int $penggunaId = null
    ): array {
        // Validasi minimal top up
        $minimalTopup = 100000; // Rp 100.000
        if ($nominal < $minimalTopup) {
            return [
                'sukses' => false,
                'error' => "Minimal top up Rp " . number_format($minimalTopup, 0, ',', '.'),
            ];
        }

        $dompet = DompetSaldo::where('klien_id', $klienId)->first();
        if (!$dompet) {
            return [
                'sukses' => false,
                'error' => 'Dompet tidak ditemukan',
            ];
        }

        $transaksi = TransaksiSaldo::create([
            'kode_transaksi' => $this->generateKodeTransaksi('INV'),
            'dompet_id' => $dompet->id,
            'klien_id' => $klienId,
            'pengguna_id' => $penggunaId,
            'jenis' => 'topup',
            'nominal' => $nominal,
            'saldo_sebelum' => $dompet->saldo_tersedia,
            'saldo_sesudah' => $dompet->saldo_tersedia, // Belum berubah
            'keterangan' => "Request top up Rp " . number_format($nominal, 0, ',', '.'),
            'status_topup' => 'pending',
            'metode_bayar' => $metodeBayar,
            'bank_tujuan' => $bankTujuan,
            'batas_bayar' => now()->addHours(24),
        ]);

        // Log aktivitas
        LogAktivitas::catat(
            'topup',
            'saldo',
            "Request top up Rp " . number_format($nominal, 0, ',', '.'),
            $penggunaId,
            $klienId,
            'transaksi_saldo',
            $transaksi->id
        );

        return [
            'sukses' => true,
            'transaksi_id' => $transaksi->id,
            'kode_transaksi' => $transaksi->kode_transaksi,
            'nominal' => $nominal,
            'metode_bayar' => $metodeBayar,
            'batas_bayar' => $transaksi->batas_bayar,
        ];
    }

    /**
     * Upload bukti transfer untuk top up
     * 
     * @param int $transaksiId
     * @param string $pathBukti Path file bukti transfer
     * @return array
     */
    public function uploadBuktiTransfer(int $transaksiId, string $pathBukti): array
    {
        $transaksi = TransaksiSaldo::find($transaksiId);
        
        if (!$transaksi) {
            return ['sukses' => false, 'error' => 'Transaksi tidak ditemukan'];
        }

        if ($transaksi->status_topup !== 'pending') {
            return ['sukses' => false, 'error' => 'Transaksi bukan pending'];
        }

        $transaksi->update(['bukti_transfer' => $pathBukti]);

        return [
            'sukses' => true,
            'pesan' => 'Bukti transfer berhasil diupload',
        ];
    }

    /**
     * Koreksi saldo manual oleh admin
     * 
     * @param int $klienId
     * @param int $nominal Positif untuk tambah, negatif untuk kurang
     * @param string $alasan
     * @param int $adminId
     * @return array
     */
    public function koreksiSaldo(int $klienId, int $nominal, string $alasan, int $adminId): array
    {
        if ($nominal == 0) {
            return [
                'sukses' => false,
                'error' => 'Nominal tidak boleh 0',
            ];
        }

        return DB::transaction(function () use ($klienId, $nominal, $alasan, $adminId) {
            
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new Exception('Dompet tidak ditemukan');
            }

            // ANTI-BONCOS: Jika pengurangan, cek saldo cukup
            if ($nominal < 0 && $dompet->saldo_tersedia < abs($nominal)) {
                return [
                    'sukses' => false,
                    'error' => 'Saldo tidak cukup untuk koreksi pengurangan',
                    'saldo_tersedia' => $dompet->saldo_tersedia,
                    'nominal_koreksi' => $nominal,
                ];
            }

            $saldoSebelum = $dompet->saldo_tersedia;

            // Lakukan koreksi
            $dompet->saldo_tersedia += $nominal;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            // Catat transaksi
            $transaksi = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi('KOR'),
                'dompet_id' => $dompet->id,
                'klien_id' => $klienId,
                'pengguna_id' => $adminId,
                'jenis' => 'koreksi',
                'nominal' => $nominal,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "Koreksi manual: {$alasan}",
                'catatan_admin' => $alasan,
                'diproses_oleh' => $adminId,
                'waktu_diproses' => now(),
            ]);

            // Log aktivitas
            LogAktivitas::catat(
                'koreksi',
                'saldo',
                "Koreksi saldo " . ($nominal > 0 ? '+' : '') . "Rp " . number_format($nominal, 0, ',', '.') . " - {$alasan}",
                $adminId,
                $klienId,
                'transaksi_saldo',
                $transaksi->id,
                ['saldo_sebelum' => $saldoSebelum],
                ['saldo_sesudah' => $dompet->saldo_tersedia]
            );

            return [
                'sukses' => true,
                'transaksi_id' => $transaksi->id,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'nominal_koreksi' => $nominal,
            ];
        });
    }

    /**
     * Hitung estimasi biaya campaign
     * 
     * @param int $klienId
     * @param int $jumlahTarget
     * @param int $hargaPerPesan
     * @return array
     */
    public function hitungEstimasi(int $klienId, int $jumlahTarget, int $hargaPerPesan = 50): array
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();

        if (!$dompet) {
            return [
                'sukses' => false,
                'error' => 'Dompet tidak ditemukan',
            ];
        }

        $estimasiBiaya = $jumlahTarget * $hargaPerPesan;
        $cukup = $dompet->saldo_tersedia >= $estimasiBiaya;
        $kekurangan = $cukup ? 0 : ($estimasiBiaya - $dompet->saldo_tersedia);
        $maksimalTarget = (int) floor($dompet->saldo_tersedia / $hargaPerPesan);

        return [
            'sukses' => true,
            'jumlah_target' => $jumlahTarget,
            'harga_per_pesan' => $hargaPerPesan,
            'estimasi_biaya' => $estimasiBiaya,
            'saldo_tersedia' => $dompet->saldo_tersedia,
            'cukup' => $cukup,
            'kekurangan' => $kekurangan,
            'sisa_setelah_kirim' => $cukup ? ($dompet->saldo_tersedia - $estimasiBiaya) : 0,
            'maksimal_target' => $maksimalTarget,
            'status_saldo' => $dompet->status_saldo,
        ];
    }

    /**
     * Finalisasi saldo setelah campaign selesai
     * 
     * Memotong sisa hold dan release untuk pesan gagal
     * 
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $totalTerkirim
     * @param int $totalGagal
     * @param int $hargaPerPesan
     * @return array
     */
    public function finalisasiCampaign(
        int $klienId,
        int $kampanyeId,
        int $totalTerkirim,
        int $totalGagal,
        int $hargaPerPesan = 50
    ): array {
        return DB::transaction(function () use ($klienId, $kampanyeId, $totalTerkirim, $totalGagal, $hargaPerPesan) {
            
            $kampanye = Kampanye::where('id', $kampanyeId)
                ->lockForUpdate()
                ->first();

            if (!$kampanye) {
                throw new Exception('Campaign tidak ditemukan');
            }

            $sisaHold = $kampanye->saldo_dihold;
            $biayaTerkirim = $totalTerkirim * $hargaPerPesan;
            $biayaGagal = $totalGagal * $hargaPerPesan;

            $hasil = [
                'biaya_terkirim' => $biayaTerkirim,
                'refund_gagal' => 0,
                'transaksi' => [],
            ];

            // Potong untuk yang terkirim (jika masih ada hold)
            if ($sisaHold > 0 && $biayaTerkirim > 0) {
                $potongResult = $this->potongSaldo($klienId, $kampanyeId, $totalTerkirim, $hargaPerPesan);
                $hasil['transaksi'][] = $potongResult;
            }

            // Release/refund untuk yang gagal
            if ($totalGagal > 0 && $kampanye->fresh()->saldo_dihold > 0) {
                $releaseResult = $this->lepasHold(
                    $klienId,
                    $biayaGagal,
                    $kampanyeId,
                    "Refund {$totalGagal} pesan gagal kirim"
                );
                $hasil['refund_gagal'] = $releaseResult['nominal_dilepas'] ?? 0;
                $hasil['transaksi'][] = $releaseResult;
            }

            // Release sisa hold jika ada (safety)
            $kampanyeFresh = $kampanye->fresh();
            if ($kampanyeFresh->saldo_dihold > 0) {
                $sisaResult = $this->lepasHold(
                    $klienId,
                    $kampanyeFresh->saldo_dihold,
                    $kampanyeId,
                    "Release sisa hold campaign selesai"
                );
                $hasil['transaksi'][] = $sisaResult;
            }

            return [
                'sukses' => true,
                'ringkasan' => $hasil,
            ];
        });
    }

    // ==================== ATOMIC DEDUCTION FOR MESSAGE DISPATCH ====================

    /**
     * Atomic saldo deduction untuk message dispatch
     * 
     * Langsung cek & potong saldo jika cukup.
     * Digunakan khusus untuk MessageDispatchService.
     * 
     * @param int $userId User ID
     * @param int $amount Jumlah saldo yang akan dipotong
     * @param string $source Sumber operasi (campaign, broadcast, api, flow)
     * @param string $referenceId ID referensi (campaign_id, broadcast_id, dll)
     * @param array $metadata Metadata tambahan
     * @return array
     * @throws \App\Exceptions\InsufficientBalanceException
     */
    public function atomicDeduction(
        int $userId,
        int $amount,
        string $source,
        string $referenceId,
        array $metadata = []
    ): array {
        // Validasi input
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        return DB::transaction(function () use ($userId, $amount, $source, $referenceId, $metadata) {
            
            // Lock row dompet untuk prevent race condition
            $dompet = DompetSaldo::where('klien_id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                // Auto create wallet if not exists (safety net)
                $this->buatDompet($userId);
                $dompet = DompetSaldo::where('klien_id', $userId)
                    ->lockForUpdate()
                    ->first();
            }

            // HARD STOP: Cek saldo cukup
            if ($dompet->saldo_tersedia < $amount) {
                throw new \App\Exceptions\InsufficientBalanceException(
                    currentBalance: $dompet->saldo_tersedia,
                    requiredAmount: $amount
                );
            }

            // Simpan snapshot sebelum operasi
            $saldoSebelum = $dompet->saldo_tersedia;
            $timestamp = now();

            // ATOMIC DEDUCTION: Potong saldo tersedia
            $dompet->saldo_tersedia -= $amount;
            $dompet->total_terpakai += $amount;
            $dompet->terakhir_transaksi = $timestamp;
            $dompet->save();

            // Catat transaksi
            $transaksi = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi('MSG'),
                'dompet_id' => $dompet->id,
                'klien_id' => $userId,
                'pengguna_id' => $userId,
                'jenis' => 'message_dispatch',
                'nominal' => -$amount, // Negatif karena keluar
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "Message dispatch via {$source} (Ref: {$referenceId})",
                'referensi' => $referenceId,
                'metadata' => json_encode($metadata),
                'created_at' => $timestamp
            ]);

            // Log aktivitas
            LogAktivitas::catat(
                'message_dispatch',
                'saldo',
                "Pemotongan saldo Rp " . number_format($amount, 0, ',', '.') . " untuk {$source}",
                $userId,
                $userId,
                'transaksi_saldo',
                $transaksi->id
            );

            return [
                'success' => true,
                'transaction_id' => $transaksi->id,
                'transaction_code' => $transaksi->kode_transaksi,
                'amount_deducted' => $amount,
                'balance_before' => $saldoSebelum,
                'balance_after' => $dompet->saldo_tersedia,
                'wallet_status' => $dompet->status_saldo,
                'timestamp' => $timestamp
            ];
        });
    }

    /**
     * Refund saldo jika message dispatch gagal
     * 
     * Mengembalikan saldo yang sudah dipotong jika pengiriman pesan gagal.
     * HARUS dipanggil dalam transaction yang sama dengan deduction.
     * 
     * @param string $transactionCode Kode transaksi sebelumnya
     * @param string $reason Alasan refund
     * @return array
     */
    public function refundFromFailedDispatch(string $transactionCode, string $reason = 'Message send failed'): array
    {
        return DB::transaction(function () use ($transactionCode, $reason) {
            
            // Cari transaksi sebelumnya
            $originalTransaction = TransaksiSaldo::where('kode_transaksi', $transactionCode)
                ->where('jenis', 'message_dispatch')
                ->first();

            if (!$originalTransaction) {
                throw new \Exception("Original transaction not found: {$transactionCode}");
            }

            $userId = $originalTransaction->klien_id;
            $refundAmount = abs($originalTransaction->nominal); // Pastikan positif

            // Lock wallet
            $dompet = DompetSaldo::where('klien_id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new \Exception('Wallet not found for refund');
            }

            // Snapshot sebelum refund
            $saldoSebelum = $dompet->saldo_tersedia;
            $timestamp = now();

            // REFUND: Kembalikan saldo
            $dompet->saldo_tersedia += $refundAmount;
            $dompet->total_terpakai -= $refundAmount;
            $dompet->terakhir_transaksi = $timestamp;
            $dompet->save();

            // Catat transaksi refund
            $refundTransaction = TransaksiSaldo::create([
                'kode_transaksi' => $this->generateKodeTransaksi('RFD'),
                'dompet_id' => $dompet->id,
                'klien_id' => $userId,
                'pengguna_id' => $userId,
                'jenis' => 'refund',
                'nominal' => $refundAmount, // Positif karena masuk
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $dompet->saldo_tersedia,
                'keterangan' => "Refund for failed dispatch (Original: {$transactionCode}) - {$reason}",
                'referensi' => $transactionCode,
                'created_at' => $timestamp
            ]);

            // Mark original transaction as refunded
            $originalTransaction->update([
                'metadata' => json_encode(array_merge(
                    json_decode($originalTransaction->metadata ?? '{}', true),
                    ['refunded' => true, 'refund_transaction' => $refundTransaction->kode_transaksi]
                ))
            ]);

            // Log aktivitas
            LogAktivitas::catat(
                'refund',
                'saldo',
                "Refund saldo Rp " . number_format($refundAmount, 0, ',', '.') . " - {$reason}",
                $userId,
                $userId,
                'transaksi_saldo',
                $refundTransaction->id
            );

            return [
                'success' => true,
                'refund_transaction_id' => $refundTransaction->id,
                'refund_amount' => $refundAmount,
                'balance_after_refund' => $dompet->saldo_tersedia,
                'original_transaction' => $transactionCode,
            ];
        });
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Pause campaign karena saldo habis
     */
    private function pauseCampaignKarenaSaldoHabis(Kampanye $kampanye): void
    {
        $kampanye->update([
            'status' => 'pause',
            'alasan_berhenti' => 'Saldo habis. Silakan top up untuk melanjutkan.',
        ]);

        // Log aktivitas
        LogAktivitas::catat(
            'auto_stop',
            'kampanye',
            "Campaign auto-stop karena saldo habis: {$kampanye->nama_kampanye}",
            null,
            $kampanye->klien_id,
            'kampanye',
            $kampanye->id
        );

        // TODO: Kirim notifikasi ke klien
        // NotificationService::kirimNotifikasiSaldoHabis($kampanye);
    }

    /**
     * Generate kode transaksi unik
     */
    private function generateKodeTransaksi(string $prefix = 'TRX'): string
    {
        $tanggal = now()->format('Ymd');
        $random = strtoupper(Str::random(5));
        return "{$prefix}-{$tanggal}-{$random}";
    }
}

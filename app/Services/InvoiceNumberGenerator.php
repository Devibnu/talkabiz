<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceNumberGenerator — Penomoran Invoice Resmi
 *
 * FORMAT:
 *   {PREFIX}/{YYYY}/{MM}/{SEQUENCE}
 *   TBZ/INV/2026/02/000123
 *
 * RULES:
 * ──────
 * ✅ Sequence reset setiap bulan
 * ✅ Atomic (DB transaction + row lock)
 * ✅ Tidak boleh lompat nomor (gap-free)
 * ✅ Prefix configurable via config('tax.invoice_prefix')
 * ❌ Jangan generate ulang invoice number yang sudah terpakai
 *
 * IMPLEMENTASI:
 * ─────────────
 * Tabel `invoice_sequences` menyimpan counter per (prefix, year, month).
 * Setiap generate() melakukan:
 *   1. DB::transaction
 *   2. lockForUpdate pada row (prefix, year, month)
 *   3. Increment last_sequence
 *   4. Return formatted number
 *
 * Jika row belum ada → firstOrCreate dengan last_sequence = 0.
 *
 * @see config/tax.php — invoice_prefix, sequence_digits
 */
class InvoiceNumberGenerator
{
    /**
     * Generate nomor invoice resmi berikutnya.
     *
     * HARUS dipanggil di dalam DB::transaction yang sama
     * dengan pembuatan invoice untuk menjamin atomicity.
     *
     * @param  int|null    $year   Override tahun (default: tahun sekarang)
     * @param  int|null    $month  Override bulan (default: bulan sekarang)
     * @param  string|null $prefix Override prefix (default: config)
     * @return array{invoice_number: string, fiscal_year: int, fiscal_month: int, sequence: int}
     */
    public function generate(?int $year = null, ?int $month = null, ?string $prefix = null): array
    {
        $prefix = $prefix ?? config('tax.invoice_prefix', 'TBZ/INV');
        $year   = $year ?? (int) now()->format('Y');
        $month  = $month ?? (int) now()->format('n');
        $digits = (int) config('tax.sequence_digits', 6);

        // Atomic: lock row → increment → return
        $sequence = DB::transaction(function () use ($prefix, $year, $month) {
            // Get or create sequence row for this period
            $row = DB::table('invoice_sequences')
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $nextSeq = $row->last_sequence + 1;

                DB::table('invoice_sequences')
                    ->where('id', $row->id)
                    ->update([
                        'last_sequence' => $nextSeq,
                        'updated_at'    => now(),
                    ]);
            } else {
                $nextSeq = 1;

                DB::table('invoice_sequences')->insert([
                    'prefix'        => $prefix,
                    'year'          => $year,
                    'month'         => $month,
                    'last_sequence' => 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            return $nextSeq;
        });

        $paddedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
        $paddedSeq   = str_pad($sequence, $digits, '0', STR_PAD_LEFT);

        $invoiceNumber = "{$prefix}/{$year}/{$paddedMonth}/{$paddedSeq}";

        Log::info('[InvoiceNumberGenerator] Generated', [
            'invoice_number' => $invoiceNumber,
            'fiscal_year'    => $year,
            'fiscal_month'   => $month,
            'sequence'       => $sequence,
        ]);

        return [
            'invoice_number' => $invoiceNumber,
            'fiscal_year'    => $year,
            'fiscal_month'   => $month,
            'sequence'       => $sequence,
        ];
    }

    /**
     * Peek next sequence tanpa increment (read-only, NO lock).
     *
     * Gunakan untuk preview / estimasi saja. Bukan untuk pembuatan invoice.
     */
    public function peekNext(?int $year = null, ?int $month = null, ?string $prefix = null): string
    {
        $prefix = $prefix ?? config('tax.invoice_prefix', 'TBZ/INV');
        $year   = $year ?? (int) now()->format('Y');
        $month  = $month ?? (int) now()->format('n');
        $digits = (int) config('tax.sequence_digits', 6);

        $row = DB::table('invoice_sequences')
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $nextSeq = ($row ? $row->last_sequence : 0) + 1;

        $paddedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
        $paddedSeq   = str_pad($nextSeq, $digits, '0', STR_PAD_LEFT);

        return "{$prefix}/{$year}/{$paddedMonth}/{$paddedSeq}";
    }

    /**
     * Get last used sequence for a specific period.
     */
    public function getLastSequence(?int $year = null, ?int $month = null, ?string $prefix = null): int
    {
        $prefix = $prefix ?? config('tax.invoice_prefix', 'TBZ/INV');
        $year   = $year ?? (int) now()->format('Y');
        $month  = $month ?? (int) now()->format('n');

        $row = DB::table('invoice_sequences')
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return $row ? (int) $row->last_sequence : 0;
    }
}

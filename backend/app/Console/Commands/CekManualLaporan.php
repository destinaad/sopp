<?php

namespace App\Console\Commands;

use App\Services\LaporanDataService;
use Illuminate\Console\Command;

/**
 * php artisan laporan:cek-manual --tanggal=2026-09-01
 * php artisan laporan:cek-manual --tanggal=2026-09-01 --loket=3
 * php artisan laporan:cek-manual --mulai=2026-09-01 --selesai=2026-09-07 --loket=3
 * php artisan laporan:cek-manual --tanggal=2026-09-01 --loket=3 --teller=7609063
 *
 * Manggil LaporanDataService::generate() LANGSUNG (bukan lewat HTTP/
 * Controller) -- skip validasi request, auth, activity log. Tujuannya
 * cuma 1: cross-check cepat angka Bentuk A/B/C vs sistem lama tanpa
 * perlu Postman/login/klik FE. JANGAN dipakai di production traffic --
 * ini alat development doang.
 *
 * --loket kosong -> Bentuk A (semua loket, span periode utuh).
 * --loket terisi + rentang 1 hari (--tanggal, ATAU --mulai=--selesai)
 *   -> Bentuk C (expand penuh rincian transaksi).
 * --loket terisi + rentang >1 hari -> Bentuk B (1 baris/tanggal).
 */
class CekManualLaporan extends Command
{
    protected $signature = 'laporan:cek-manual
        {--loket= : loket_id, kosongin buat Bentuk A (semua loket)}
        {--tanggal= : YYYY-MM-DD, shortcut buat 1 hari (mulai=selesai=ini)}
        {--mulai= : YYYY-MM-DD, dipakai bareng --selesai kalau bukan 1 hari}
        {--selesai= : YYYY-MM-DD}
        {--teller= : teller_id legacy (user_id_sopp), opsional}
        {--refresh : paksa bypass cache, sama kayak tombol Refresh Data di FE}';

    protected $description = 'Cross-check manual angka Laporan (Bentuk A/B/C) tanpa lewat HTTP, buat dibandingkan ke sistem lama';

    public function handle(LaporanDataService $laporanDataService): int
    {
        $tanggalMulai = $this->option('tanggal') ?? $this->option('mulai');
        $tanggalSelesai = $this->option('tanggal') ?? $this->option('selesai') ?? $tanggalMulai;

        if (! $tanggalMulai || ! $tanggalSelesai) {
            $this->error('Wajib isi --tanggal=YYYY-MM-DD, ATAU --mulai=YYYY-MM-DD --selesai=YYYY-MM-DD.');

            return self::FAILURE;
        }

        $loketId = $this->option('loket') !== null ? (int) $this->option('loket') : null;
        $mode = $tanggalMulai === $tanggalSelesai ? 'harian' : 'rentang';

        $this->info("Mode: {$mode} | loket_id: " . ($loketId ?? 'semua (Bentuk A)') . " | {$tanggalMulai} s/d {$tanggalSelesai}");
        if ($this->option('refresh')) {
            $this->warn('--refresh aktif: cache di-bypass, fetch bridge LIVE.');
        }

        try {
            $data = $laporanDataService->generate(
                mode: $mode,
                loketId: $loketId,
                tanggalMulai: $tanggalMulai,
                tanggalSelesai: $tanggalSelesai,
                tellerId: $this->option('teller'),
                actor: null,
                paginasi: false,
                forceRefresh: (bool) $this->option('refresh'),
            );
        } catch (\Throwable $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
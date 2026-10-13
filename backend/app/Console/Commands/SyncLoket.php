<?php

namespace App\Console\Commands;

use App\Exceptions\BridgeException;
use App\Models\Loket;
use App\Services\BridgeService;
use Illuminate\Console\Command;

/**
 * php artisan sopp:sync-loket
 *
 * Dijalankan MANUAL sesekali -- bukan cron otomatis, konsisten sama
 * prinsip proyek ini bahwa semua fetch ke bridge dipicu aksi
 * eksplisit (§6.3). Data loket nyaris tidak pernah berubah, jadi
 * cukup dijalankan manual saat memang ada perubahan (loket baru
 * dibuka, nama loket direvisi).
 */
class SyncLoket extends Command
{
    protected $signature = 'sopp:sync-loket';
    protected $description = 'Sinkronisasi nama loket dari server SOPP lama ke tabel lokets lokal';

    public function handle(BridgeService $bridgeService): int
    {
        $this->info('Mengambil daftar loket dari bridge...');

        try {
            $data = $bridgeService->fetchMasterLoket();
        } catch (BridgeException $e) {
            $this->error('Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        $sekarang = now();
        $rows = array_map(static fn (array $item) => [
            'loket_id'   => $item['loket_id'],
            'nama_loket' => $item['loket_name'],
            'synced_at'  => $sekarang,
            'updated_at' => $sekarang,
            'created_at' => $sekarang,
        ], $data);

        Loket::upsert($rows, ['loket_id'], ['nama_loket', 'synced_at', 'updated_at']);

        $this->info(count($rows) . ' loket berhasil disinkron.');
        return self::SUCCESS;
    }
}
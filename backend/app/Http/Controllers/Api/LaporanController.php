<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ThrottlesBridgeRefresh;
use App\Http\Controllers\Api\Concerns\ValidatesFilterLaporan;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\LaporanDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/laporan -- §Laporan & Export. Controller ini SENGAJA
 * tipis: validasi + otorisasi + activity log, semua logic Bentuk A/B/C
 * ada di LaporanDataService (lihat docblock kelasnya buat detail
 * kontrak). Controller tidak pernah menyentuh BridgeService/cache
 * langsung.
 *
 * Validasi filter (mode/loket_id/tanggal_/teller_id) di
 * ValidatesFilterLaporan -- dipakai bareng LaporanExportController,
 * BUKAN di-copy manual, karena "download = apa yang ditampilkan" (§A
 * v10) berarti resolusi tanggal/mode harus identik di 3 endpoint.
 *
 * Tombol "Refresh Data" di FE (?refresh=true) -- pola SAMA PERSIS kayak
 * yang sudah ada di SetoranEntryController/PelunasanController
 * (trait ThrottlesBridgeRefresh), BUKAN mekanisme baru. Gunanya:
 * data sistem lama SUDAH dijelaskan bisa telat posting koreksi setelah
 * H+2 (tanggal itu udah kadung di-cache 30 hari) -- refresh manual dari
 * layar (siapa aja yang punya akses halaman Laporan, gak perlu akses
 * server) maksa bypass cache yang ada. `export()`/`download()` SENGAJA
 * TIDAK dapet param ini sendiri -- gak perlu, karena cache-nya di-share
 * lintas 3 endpoint (key sama persis), jadi begitu 1 tanggal+loket
 * di-refresh dari sini, export/download abis itu otomatis kebaca data
 * yang udah seger juga.
 */

class LaporanController extends Controller
{
    use ThrottlesBridgeRefresh;
    use ValidatesFilterLaporan;

    public function __construct(
        private readonly LaporanDataService $laporanDataService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAksesHalaman($request);

        $filter = $this->validasiFilterLaporan($request);
        $validasiHalaman = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $halaman = (int) ($validasiHalaman['page'] ?? 1);
        $forceRefresh = $request->boolean('refresh');

        if ($forceRefresh) {
            // Key WAJIB termasuk tanggal+loket (§8.5, sama kayak trait
            // ini dipakai di tempat lain) -- 'semua' dipakai kalau
            // loket_id kosong (Bentuk A), biar gak nabrak sama key
            // request loket_id=null buat tanggal yang sama dari user
            // lain (rate limit per KOMBINASI filter, bukan per user
            // doang -- konsisten sama "cache di-key per tanggal+loket,
            // bukan per user" yang udah jadi prinsip dari awal).
            $this->tolakJikaKenaRateLimit(
                'refresh-laporan:'.$request->user()->id.':'.$filter['mode'].':'.($filter['loket_id'] ?? 'semua').':'.$filter['tanggal_mulai'].':'.$filter['tanggal_akhir']
            );
        }

        $data = $this->laporanDataService->generate(
            mode: $filter['mode'],
            loketId: $filter['loket_id'],
            tanggalMulai: $filter['tanggal_mulai'],
            tanggalSelesai: $filter['tanggal_akhir'],
            tellerId: $filter['teller_id'],
            actor: $request->user(),
            halaman: $halaman,
            forceRefresh: $forceRefresh,
        );

        // Log ringan -- siapa buka laporan apa, bukan detail isinya
        // (samakan level detail dengan 'riwayat_lintas_loket_dilihat' di
        // RiwayatSetoranController).
        ActivityLog::catat($request->user(), 'laporan_dilihat', null, null, [
            'mode' => $filter['mode'],
            'loket_id' => $filter['loket_id'],
            'tanggal_mulai' => $filter['tanggal_mulai'],
            'tanggal_akhir' => $filter['tanggal_akhir'],
            'teller_id' => $filter['teller_id'],
            'refresh' => $forceRefresh,
        ]);

        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Pola sama persis kayak HakAksesController/LogAktivitasController
     * (shared logic #8: is_superadmin || bisa_X inline). Dipakai juga
     * dari LaporanExportController (bukan lewat trait -- cuma 3 baris,
     * duplikasi kecil ini konsisten sama pola project yang sudah ada,
     * beda kasus dari validasi filter yang lebih riskan kalau di-drift).
     */
    private function authorizeAksesHalaman(Request $request): void
    {
        $user = $request->user();
        if (! $user->is_superadmin && ! $user->bisa_akses_laporan) {
            abort(403, 'Tidak memiliki akses ke halaman ini.');
        }
    }
}
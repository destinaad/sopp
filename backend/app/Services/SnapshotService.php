<?php

namespace App\Services;

use App\Models\SetoranHarian;
use App\Exceptions\BridgeException;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Carbon\Carbon;

/**
 * Ini yang "megang keputusan" TTL yang sudah dibahas panjang --
 * BridgeService di bawahnya cuma tau cara ngomong ke bridge, TIDAK
 * tau kapan seharusnya dipanggil. Semua controller (Card A, Card B,
 * Dashboard) manggil SATU method publik di sini, TIDAK pernah
 * manggil BridgeService langsung -- supaya aturan TTL cuma ada di
 * SATU tempat.
 */
class SnapshotService
{
    private const TTL_MENIT = 10;

    public function __construct(
        private readonly BridgeService $bridgeService,
    ) {
    }

    /**
     * @return array{data: array, grand_total: int, fetched_at: string, data_terkini: bool}
     * @throws \App\Exceptions\BridgeException kalau bridge gagal DAN gak ada
     *         snapshot lama sama sekali buat di-fallback-in (beneran gak ada
     *         data apa-apa, bukan cuma basi).
     */
    public function getForLoketTanggal(int $loketId, string $tanggal, bool $forceRefresh = false, ?User $actor = null): array
    {
        // Diambil per KOMBINASI loket+tanggal, LINTAS SEMUA user --
        // kalau loket X punya 2 teller, dua-duanya baca/tulis
        // snapshot yang sama secara konsep (walau tersimpan di baris
        // setoran_harian masing-masing -- lihat catatan di bawah).
        $rowRow = SetoranHarian::where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->get(['id', 'snapshot_sopp']);

        if (! $forceRefresh) {
            $snapshotSegar = $this->cariSnapshotYangMasihSegar($rowRow);
            if ($snapshotSegar !== null) {
                return $snapshotSegar + ['data_terkini' => true];
            }
        }

        try {
            $hasilFetch = $this->bridgeService->fetch($tanggal, $tanggal, $loketId, $actor);
        } catch (BridgeException $e) {
            // FALLBACK (18 Agustus 2026): bridge gagal -- daripada
            // langsung nyerah, coba pakai snapshot LAMA (walau sudah
            // lewat TTL) kalau ada. Beda dari cariSnapshotYangMasihSegar()
            // di atas -- di sini snapshot APAPUN umurnya diterima, asal
            // ada, ditandai data_terkini:false biar FE bisa kasih warning.
            // Kalau beneran gak ada snapshot sama sekali (belum pernah
            // sukses fetch buat loket+tanggal ini), baru dilempar exception
            // ke pemanggil -- itu kondisi yang genuinely gak bisa ditolong.
            $snapshotLama = $this->cariSnapshotApapunUmurnya($rowRow);
            if ($snapshotLama !== null) {
                return $snapshotLama + ['data_terkini' => false];
            }
            throw $e;
        }

        $snapshotBaru = [
            'data'        => $hasilFetch['data'],
            'grand_total' => $hasilFetch['grand_total'],
            'fetched_at'  => now()->toDateTimeString(),
        ];

        // Hanya ditulis untuk kombinasi loket+tanggal yang SUDAH
        // punya row setoran_harian (sudah ada teller yang pernah
        // menyentuh tanggal ini) -- kalau belum ada row sama sekali,
        // hasil fetch dibuang, TIDAK membuat row baru. Datanya tetap
        // dikembalikan ke pemanggil (transient), cuma tidak permanen.
        if ($rowRow->isNotEmpty()) {
            // Update SEMUA baris loket+tanggal ini sekaligus (bukan
            // cuma satu) -- kalau ada 2 teller di loket yang sama,
            // dua-duanya harus lihat snapshot yang identik, bukan
            // salah satu ketinggalan data lama.
            SetoranHarian::whereIn('id', $rowRow->pluck('id'))
                ->update(['snapshot_sopp' => json_encode($snapshotBaru)]);
        }

        return $snapshotBaru + ['data_terkini' => true];
    }

    /**
     * Feed aktivitas loket buat widget "Riwayat Terakhir" Dashboard
     * Teller -- per-LOKET (siapapun tellernya), bentuk "1 baris = 1
     * kejadian submit", BUKAN "1 baris = 1 tanggal". Draft TIDAK
     * disertakan sama sekali (keputusan: privasi kerjaan-belum-
     * selesai, gak peduli punya sendiri atau punya teller lain).
     *
     * belum_input MUNCUL LAGI di sini (sempat di-revert dari versi
     * yang salah di DashboardTellerController) -- sekarang aman
     * karena scope-nya konsisten loket+loket (bukan user+loket kayak
     * sebelumnya), jadi gak ada lagi false-positive "bukan giliran
     * gue". BridgeService dipanggil DI SINI, bukan di controller --
     * itu aturan class ini sendiri (lihat docblock di atas).
     *
     * @return array<int, array{tanggal_transaksi: string, nama_teller: ?string, total_pendapatan: ?int, total_setor: ?int, selisih: ?int, status_keuangan: ?string, status_workflow: string}>
     */
    public function aktivitasTerakhirUntukLoket(int $loketId, string $sebelumTanggal, ?User $actor = null): array
    {
        $tanggalAwal = Carbon::parse($sebelumTanggal)->subDays(30)->toDateString();
        $tanggalAkhir = Carbon::parse($sebelumTanggal)->subDay()->toDateString();

        $tanggalBridge = $this->tanggalBridgeUntukLoket($loketId, $tanggalAwal, $tanggalAkhir, $actor);

        $rowSubmitted = SetoranHarian::where('loket_id', $loketId)
            ->where('status', 'submitted')
            ->whereBetween('tanggal_transaksi', [$tanggalAwal, $tanggalAkhir])
            ->with('user:id,nama')
            ->orderByDesc('tanggal_transaksi')
            ->orderByDesc('submitted_at')
            ->get();

        $aktivitas = collect();

        foreach ($rowSubmitted as $row) {
            $adaSnapshot = $row->snapshot_sopp !== null;

            $aktivitas->push([
                'tanggal_transaksi' => $row->tanggal_transaksi->toDateString(),
                'nama_teller' => $row->user->nama ?? null,
                'total_pendapatan' => $adaSnapshot ? $row->totalPendapatanSopp() : null,
                'total_setor' => $row->totalSetor(),
                'selisih' => $adaSnapshot ? $row->selisih() : null,
                'status_keuangan' => $adaSnapshot ? ($row->isLunas() ? 'lunas' : 'belum_lunas') : null,
                'status_workflow' => 'submitted',
            ]);
        }

        // Tanggal yang bridge punya transaksinya, tapi loket ini NOL
        // row submitted (siapapun) -- beneran belum ada yang nulis,
        // bukan "bukan giliran teller tertentu".
        $tanggalSudahAdaAktivitas = $rowSubmitted->pluck('tanggal_transaksi')
            ->map(fn ($t) => $t->toDateString())
            ->unique();

        foreach ($tanggalBridge->diff($tanggalSudahAdaAktivitas) as $tanggal) {
            $aktivitas->push([
                'tanggal_transaksi' => $tanggal,
                'nama_teller' => null,
                'total_pendapatan' => null,
                'total_setor' => null,
                'selisih' => null,
                'status_keuangan' => null,
                'status_workflow' => 'belum_input',
            ]);
        }

        return $aktivitas
            ->sortByDesc('tanggal_transaksi')
            ->values()
            ->take(8)
            ->all();
    }

    private function tanggalBridgeUntukLoket(int $loketId, string $tanggalAwal, string $tanggalAkhir, ?User $actor): \Illuminate\Support\Collection
{
    $cacheKey = "aktivitas_bridge:{$loketId}:{$tanggalAwal}:{$tanggalAkhir}";

    $tanggalBridge = Cache::get($cacheKey);
    if ($tanggalBridge !== null) {
        return collect($tanggalBridge);
    }

    try {
        $hasilBridge = $this->bridgeService->fetch($tanggalAwal, $tanggalAkhir, $loketId, $actor);
        $tanggalBridge = collect($hasilBridge['data'])->pluck('tanggal')->unique()->values();

        // Cache cuma kalau SUKSES -- kegagalan jangan ikut ke-cache,
        // biar dalam TTL yang sama tetep ada percobaan retry ke
        // bridge, bukan keburu nge-lock ke hasil kosong selama 10 menit.
        Cache::put($cacheKey, $tanggalBridge->all(), self::TTL_MENIT * 60);

        return $tanggalBridge;
    } catch (BridgeException $e) {
        return collect();
    }
}

    /**
     * @param  \Illuminate\Support\Collection<int, SetoranHarian>  $rows
     * @return array{data: array, grand_total: int, fetched_at: string}|null
     */
    private function cariSnapshotYangMasihSegar($rows): ?array
    {
        foreach ($rows as $row) {
            $snapshot = $row->snapshot_sopp;

            if (! is_array($snapshot) || ! isset($snapshot['fetched_at'])) {
                continue;
            }

            $umurMenit = Carbon::parse($snapshot['fetched_at'])->diffInMinutes(now());

            if ($umurMenit < self::TTL_MENIT) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * Sama kayak cariSnapshotYangMasihSegar(), TAPI tanpa cek umur --
     * dipakai KHUSUS buat fallback pas bridge gagal, ambil snapshot
     * apapun yang ada (walau sudah lama basi), daripada gak ada
     * apa-apa sama sekali.
     *
     * @param  \Illuminate\Support\Collection<int, SetoranHarian>  $rows
     * @return array{data: array, grand_total: int, fetched_at: string}|null
     */
    private function cariSnapshotApapunUmurnya($rows): ?array
    {
        foreach ($rows as $row) {
            $snapshot = $row->snapshot_sopp;

            if (is_array($snapshot) && isset($snapshot['fetched_at'])) {
                return $snapshot;
            }
        }

        return null;
    }
}
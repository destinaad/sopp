<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ThrottlesBridgeRefresh;
use App\Http\Controllers\Controller;
use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\LaporanDataService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/dashboard -- dipakai bareng route /admin/dashboard
 * dan /verifikator/dashboard, data & logic 100% sama, gak ada
 * percabangan berdasarkan route mana yang dipanggil.
 *
 * Ditulis dari dashboard-admin-catatan-desain.md (revisi terakhir),
 * DENGAN SATU DEVIASI SADAR dari dokumen itu: dokumen bilang bagian 3
 * (arsitektur fetch pendapatan) dan 6c (nama dari sistem lama yang
 * belum input) masih "MENUNGGU KEPUTUSAN LAPORAN" karena fitur Laporan
 * dianggap belum didesain. Itu sudah TIDAK BENAR lagi -- LaporanController
 * + LaporanDataService di codebase ini sudah lengkap jadi (mode=harian,
 * S3), dan sudah efektif memilih Skenario B (borongan, loket_id null,
 * 1 call bridge) buat kasus "1 hari, semua loket" -- persis kasus yang
 * dipakai controller ini. Supaya gak ada 2 jalur kode beda buat data
 * yang sama (ketakutan yang jadi alasan bagian 0 dokumen ini), file ini
 * REUSE LANGSUNG dua method LaporanDataService yang sudah dibikin
 * public khusus buat ini: ambilDetailSemuaLoketHarian() (sumber bridge
 * + cache, cache key SAMA PERSIS dengan Laporan mode=harian tanggal
 * yang sama) dan drillDownTeller() (gabungan baris lokal + nama dari
 * bridge yang belum py row lokal). Konsekuensinya bagian 13 (sequential
 * vs paralel) otomatis gak relevan (cuma 1 call bridge per tanggal) dan
 * bagian 14 (belum_input gak kena cache SnapshotService) juga gak
 * relevan (jalur ini emang gak pernah pakai SnapshotService).
 *
 * Deviasi kedua (kecil): dokumen bilang loket yang tampil di tabel
 * murni dari bridge row-presence. Tapi ambilDetailSemuaLoketHarian()
 * balikin data=[] kalau bridge gagal TOTAL (BridgeException, gak ada
 * fallback ke snapshot lama kayak SnapshotService) -- kalau loket
 * visibility murni dari bridge, bridge gagal total = tabel kosong
 * total, padahal itu justru saat paling penting buat Bu Wiwik lihat
 * status loket. Jadi di sini loket visibility = bridge rows UNION
 * loket yang py baris setoran_harian lokal (apapun statusnya) di
 * tanggal itu -- pola yang sama persis kayak LaporanDataService::
 * bentukA() gabung $rowsBridge->keys() dengan $metodePerLoket lokal.
 */
class DashboardAdminController extends Controller
{
    use ThrottlesBridgeRefresh;

    public function __construct(
        private readonly LaporanDataService $laporanDataService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAkses($user);

        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $tanggal = $validated['tanggal'] ?? Carbon::today()->toDateString();
        $forceRefresh = $request->boolean('refresh');

        // Bagian 4: rate limit HANYA buat refresh=true, window 20 detik
        // (bukan default 10 milik pemanggil lain), key ikut tanggal
        // (konvensi project -- lihat komentar ThrottlesBridgeRefresh).
        if ($forceRefresh) {
            $this->tolakJikaKenaRateLimit(
                'refresh-dashboard-admin:'.$user->id.':'.$tanggal,
                20,
            );
        }

        // Bagian 3 (resolved -- lihat docblock kelas): reuse LANGSUNG
        // sumber bridge + cache Laporan mode=harian, bukan jalur baru.
        $bridge = $this->laporanDataService->ambilDetailSemuaLoketHarian($tanggal, $user, $forceRefresh);
        $rowsBridgePerLoket = collect($bridge['data'])->groupBy('loket_id');

        // Semua baris lokal APAPUN statusnya (bagian 6b eksplisit minta
        // draft ikut) buat tanggal ini, 1 query batched semua loket.
        $setoranHariIniPerLoket = SetoranHarian::where('tanggal_transaksi', $tanggal)
            ->with('user:id,nama,user_id_sopp')
            ->get()
            ->groupBy('loket_id');

        $loketIdRelevan = $rowsBridgePerLoket->keys()
            ->merge($setoranHariIniPerLoket->keys())
            ->unique()->sort()->values();

        $lokets = Loket::whereIn('loket_id', $loketIdRelevan->all())->get()->keyBy('loket_id');

        $items = $loketIdRelevan->map(function (int $loketId) use ($tanggal, $lokets, $rowsBridgePerLoket, $setoranHariIniPerLoket, $bridge) {
            $rowsBridgeLoket = $rowsBridgePerLoket->get($loketId, collect());
            $setoranLoket = $setoranHariIniPerLoket->get($loketId, collect());

            $submitted = $setoranLoket->where('status', 'submitted');
            $draft = $setoranLoket->where('status', 'draft');

            // Bagian 5: status agregat, paling belum selesai menang.
            $statusAgregat = match (true) {
                $draft->isNotEmpty() => 'draft',
                $submitted->isNotEmpty() => 'submitted',
                default => 'belum_input',
            };

            // Bagian 3: total_pendapatan_sopp null kalau bridge gagal
            // total, BUKAN 0 -- konsisten prinsip null-vs-0 yang sama
            // kayak bagian 7.
            $totalPendapatanSopp = $bridge['gagal'] ? null : (int) $rowsBridgeLoket->sum('total');

            // Bagian 7: total_setor submitted-only, null (bukan 0) kalau
            // gak ada baris submitted -- cek existence baris DULUAN,
            // gak nebak dari hasil SUM (SetoranHarian::
            // totalSetorUntukLoketTanggal() return type int mentah,
            // 0 buat "belum ada" MAUPUN "sudah setor tapi 0").
            $totalSetor = $submitted->isEmpty()
                ? null
                : SetoranHarian::totalSetorUntukLoketTanggal($loketId, $tanggal);

            $selisih = ($totalPendapatanSopp !== null && $totalSetor !== null)
                ? $totalPendapatanSopp - $totalSetor
                : null;

            // Bagian 6a: nama ringkas, submitted SAJA, alfabetis.
            $tellerRingkas = $submitted->pluck('user.nama')->filter()->unique()->sort()->values()->all();

            // Bagian 6b+6c: SEMUA baris lokal (draft ikut, tanpa nominal
            // -- drillDownTeller() emang gak pernah kirim nominal sama
            // sekali, jadi privasi draft otomatis kejaga) + nama dari
            // bridge yang belum py row lokal sama sekali (status
            // belum_input). Urutan: yang belum submit duluan (draft &
            // belum_input digabung, gak dibedain lagi urutannya di
            // antara keduanya -- DIKONFIRMASI user, bukan lagi asumsi
            // sementara; dokumen desain sendiri cuma eksplisit soal
            // "belum submit duluan"), lalu submitted -- dalam tiap
            // grup alfabetis.
            $detailTeller = $this->laporanDataService->drillDownTeller($setoranLoket, $rowsBridgeLoket);
            usort($detailTeller, function (array $a, array $b) {
                $belumSelesaiA = $a['status_workflow'] !== 'submitted';
                $belumSelesaiB = $b['status_workflow'] !== 'submitted';
                if ($belumSelesaiA !== $belumSelesaiB) {
                    return $belumSelesaiB <=> $belumSelesaiA;
                }

                return strcasecmp($a['nama'] ?? '', $b['nama'] ?? '');
            });

            return [
                'loket_id' => $loketId,
                'nama_loket' => $lokets->get($loketId)?->nama_loket,
                'status' => $statusAgregat,
                'total_pendapatan_sopp' => $totalPendapatanSopp,
                'total_setor' => $totalSetor,
                'selisih' => $selisih,
                'teller' => $tellerRingkas,
                'detail_teller' => $detailTeller,
            ];
        })->values();

        // Bagian 8: ringkasan 4 kartu, kriteria total_loket SAMA dengan
        // loket yang tampil di baris tabel (dinamis, bukan Loket::count()
        // polos). Loket berstatus draft SENGAJA gak masuk sudah_submit
        // maupun belum_setor.
        $ringkasan = [
            'total_loket' => $items->count(),
            'sudah_submit' => $items->where('status', 'submitted')->count(),
            'belum_setor' => $items->where('status', 'belum_input')->count(),
            'ada_selisih' => $items->filter(fn (array $i) => $i['selisih'] !== null && $i['selisih'] !== 0)->count(),
        ];

        return response()->json([
            'status' => 'ok',
            'data' => [
                'tanggal' => $tanggal,
                'bridge_gagal' => $bridge['gagal'],
                'ringkasan' => $ringkasan,
                'items' => $items->values()->all(),
            ],
        ]);
    }

    /**
     * Bagian 1: bisa_akses_laporan (sudah di-OR is_superadmin lewat
     * kapabilitasUntukApi()) ATAU bisa_edit_setoran_semua_loket
     * (mentah, TIDAK ikut OR). Ditulis inline (bukan trait/helper
     * shared) -- catatan kecil di dokumen bilang ini sebaiknya
     * dipusatkan kalau nanti ada endpoint lain butuh kombinasi sama,
     * tapi belum ada pemanggil kedua sekarang jadi belum ditarik keluar
     * (pola sama kayak LaporanController::authorizeAksesHalaman()
     * yang juga sengaja gak lewat trait).
     */
    private function authorizeAkses(User $user): void
    {
        if (! ($user->kapabilitasUntukApi()['bisa_akses_laporan'] || $user->bisa_edit_setoran_semua_loket)) {
            abort(403, 'Tidak memiliki akses ke halaman ini.');
        }
    }
}
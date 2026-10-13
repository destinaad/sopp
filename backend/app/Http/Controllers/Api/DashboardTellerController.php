<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BridgeException;
use App\Http\Controllers\Controller;
use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/teller/dashboard -- §7.1. Selalu "hari ini", untuk loket
 * teller yang login. TIDAK ada endpoint lain di controller ini --
 * halaman ini murni baca (read-only), semua aksi input ada di
 * SetoranEntryController/PelunasanController (halaman terpisah).
 *
 * =====================================================================
 * PERUBAHAN dari audit shared logic (semua di-scope ke Dashboard
 * Teller saja -- Card A, Detail Setoran, Pelunasan, Riwayat Setoran,
 * Laporan SENGAJA belum disentuh, walau beberapa punya bug/pola yang
 * sama persis. Lihat "HEADS-UP" di bawah untuk daftarnya.
 * =====================================================================
 *
 * FIX 1 (shared logic #3 -- Perhitungan finansial): total_setor buat
 * hitung selisih "hari ini" sekarang GABUNGAN semua teller di loket
 * ini, HANYA yang statusnya submitted (SetoranHarian::
 * totalSetorUntukLoketTanggal(), lihat komentar di model buat alasan
 * lengkap). Sebelumnya cuma total_setor milik user yang login doang
 * ($setoranHarian->totalSetor()) -- itu salah kalau 1 loket punya
 * >1 teller aktif di hari yang sama, DAN draft (belum submit) ikut
 * kehitung padahal belum resmi.
 *
 * FIX 2 (bug isLunas() null): riwayatTerakhir() sebelumnya manggil
 * $row->isLunas() mentah-mentah buat isi status_keuangan. isLunas()
 * itu cuma nanya "selisih() === 0?" -- kalau selisih() null (data
 * gak kebaca), null === 0 di PHP hasilnya false, jadi row yang
 * "gak diketahui" salah kesimpulan jadi "belum_lunas". Sekarang
 * di-guard: kalau snapshot_sopp null, status_keuangan ikut null,
 * BUKAN 'belum_lunas'. Field selisih di baris yang sama sudah lama
 * benar (sudah ada guard terpisah) -- sekarang dua field ini konsisten,
 * gak ada lagi kasus selisih:null tapi status_keuangan:'belum_lunas'
 * di baris yang sama.
 *
 * FIX 3 (field baru pendapatan_kemarin_status): sebelumnya FE cuma
 * dapat 1 status (pendapatan_sopp_status) buat data HARI INI. Tapi
 * persentase_vs_kemarin bisa null karena 3 sebab beda: (a) data hari
 * ini gagal, (b) data kemarin gagal, (c) data kemarin berhasil tapi
 * emang 0. FE gak bisa bedain (b) dan (c) tanpa field tambahan ini.
 * >>> PENJELASAN BUAT FE (taruh di catatan API/kirim ke tim FE): <
 *   - pendapatan_sopp_status ATAU pendapatan_kemarin_status ada isinya
 *     ('gagal_dimuat') -> tampilkan "data tidak bisa dimuat", BUKAN "0%"
 *   - dua-duanya null, TAPI persentase_vs_kemarin tetap null -> berarti
 *     kemarin memang tidak ada pendapatan sama sekali, boleh ditampilkan
 *     sebagai "kemarin tidak ada pendapatan" (bukan "0%" yang bisa
 *     disalahartikan sebagai "tidak berubah dari kemarin")
 *   - dua-duanya null DAN persentase_vs_kemarin ada angkanya -> tampilkan
 *     apa adanya, kondisi normal
 *   Field pendapatan_kemarin (angka mentah rupiah) TIDAK ditambahkan --
 *   response cuma kasih persentase-nya. Kalau FE nanti butuh angka
 *   mentah kemarin buat ditampilkan (bukan cuma growth %), ini perlu
 *   diomongin lagi, belum ke-cover di perubahan ini.

 */
class DashboardTellerController extends Controller
{
    public function __construct(
        private readonly SnapshotService $snapshotService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->loket_id === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akun ini tidak terhubung ke loket manapun.',
                'errors' => (object) [],
            ], 403);
        }

        $loketId = $user->loket_id;
        $tanggalHariIni = Carbon::today()->toDateString();
        $tanggalKemarin = Carbon::yesterday()->toDateString();

        // Dua fetch independen -- kegagalan salah satu tidak boleh
        // menggagalkan yang lain (§6.2, BridgeException di-scope
        // ke bagian yang bergantung padanya saja).
        try {
            $snapshotHariIni = $this->snapshotService->getForLoketTanggal($loketId, $tanggalHariIni, false, $user);
        } catch (BridgeException $e) {
            $snapshotHariIni = null;
        }

        try {
            $snapshotKemarin = $this->snapshotService->getForLoketTanggal($loketId, $tanggalKemarin, false, $user);
        } catch (BridgeException $e) {
            $snapshotKemarin = null;
        }

        $pendapatanSoppStatus = $snapshotHariIni === null ? 'gagal_dimuat' : null;
        // FIX 3: status terpisah buat data kemarin -- sebelumnya cuma
        // ada status buat hari ini, FE gak bisa bedain "kemarin gagal"
        // vs "kemarin memang 0". Lihat penjelasan lengkap di komentar
        // class di atas.
        $pendapatanKemarinStatus = $snapshotKemarin === null ? 'gagal_dimuat' : null;

        // FIX 6 (20 Agustus 2026): terusin flag data_terkini dari SnapshotService
        // ke FE, biar angka basi (fallback) gak ditampilin diam-diam tanpa warning.
        $dataTerkini = $snapshotHariIni['data_terkini'] ?? null;
        $pendapatanSopp = $snapshotHariIni['grand_total'] ?? null;
        $pendapatanKemarin = $snapshotKemarin['grand_total'] ?? null;

        // FIX 1: total_setor sekarang gabungan SEMUA teller di loket
        // ini yang statusnya SUDAH SUBMIT (bukan cuma milik $user yang
        // login, dan bukan termasuk draft siapapun -- termasuk draft
        // punya $user sendiri). Manggil method shared yang sama persis
        // dipakai SetoranHarian::selisih(), supaya cara hitungnya SATU
        // sumber kebenaran. Sengaja TIDAK ada pengecualian buat row
        // milik $user sendiri walau dia lagi aktif ngisi draft hari
        // ini -- itu keputusan sadar: draft belum resmi, jangan sampai
        // dashboard bikin teller merasa "sudah setor" padahal belum
        // pernah pencet submit.
        $totalSetor = SetoranHarian::totalSetorUntukLoketTanggal($loketId, $tanggalHariIni);
        $selisih = $pendapatanSopp !== null ? ($pendapatanSopp - $totalSetor) : null;
        $adaSelisih = $selisih !== null ? ($selisih !== 0) : null;

        $setoranHarianSaya = SetoranHarian::where('user_id', $user->id)
            ->where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggalHariIni)
            ->first();
        $statusSetoran = $setoranHarianSaya === null ? 'belum_input' : $setoranHarianSaya->status;

        $persentaseVsKemarin = null;
        if ($pendapatanSopp !== null && $pendapatanKemarin !== null && $pendapatanKemarin > 0) {
            $persentaseVsKemarin = round((($pendapatanSopp - $pendapatanKemarin) / $pendapatanKemarin) * 100, 1);
        }

        return response()->json([
            'status' => 'ok',
            'data' => [
                'nama_teller' => $user->nama,
                'nama_loket' => Loket::find($loketId)?->nama_loket,
                'tanggal_transaksi' => $tanggalHariIni,
                'tanggal_hari_ini' => $tanggalHariIni, // FIX 4: nama field yang diminta FE, tanggal_transaksi tetap ada
                'pendapatan_sopp' => $pendapatanSopp,
                'pendapatan_sopp_status' => $pendapatanSoppStatus,
                'data_terkini' => $dataTerkini, // FIX 6
                'pendapatan_kemarin_status' => $pendapatanKemarinStatus,
                'persentase_vs_kemarin' => $persentaseVsKemarin,
                'status_setoran' => $statusSetoran,
                'selisih' => $selisih,
                'ada_selisih' => $adaSelisih,
                'fetched_at' => $snapshotHariIni['fetched_at'] ?? null,
                // FIX 5: sekarang feed aktivitas per-LOKET (bukan riwayat
                // personal per-user lagi) -- logic bridge-nya di dalam
                // SnapshotService, controller ini gak pernah nyentuh
                // BridgeService langsung. Lihat docblock method-nya di
                // SnapshotService::aktivitasTerakhirUntukLoket() buat
                // histori lengkap kenapa ini berubah beberapa kali.
                'aktivitas_terakhir' => $this->snapshotService->aktivitasTerakhirUntukLoket($loketId, $tanggalHariIni, $user),
            ],
        ]);
    }
}

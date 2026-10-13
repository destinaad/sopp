<?php

namespace App\Services;

use App\Exceptions\BridgeException;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Timpa total dari versi lama (perLoket()/perTanggal()/detailPenuh()
 * sudah tidak dipakai lagi). Versi ini dibangun dari kontrak "Bentuk
 * A/B/C" (dokumen Kontrak-Keputusan-Laporan + Kontrak-API-Laporan-
 * untuk-FE.md) -- ringkas:
 *
 *   - loket_id KOSONG  -> Bentuk A: 1 baris/LOKET, span 1 periode utuh
 *     (Skenario 1 bulanan, 3 harian, 5 rentang -- NAMA field sama
 *     lintas ketiganya, tapi `drill_down_teller` CUMA applicable/terisi
 *     di S3 -- lihat poin BridgeService di bawah kenapa S3 beda sumber
 *     dari S1/S5, bukan cuma beda isi).
 *   - loket_id TERISI + mode bulanan/rentang -> Bentuk B: 1 baris/TANGGAL
 *     (Skenario 2, 6). Hari yang benar-benar kosong (bridge sukses tapi
 *     tanpa transaksi, dan tanpa setoran submitted) TIDAK dibuat barisnya.
 *   - loket_id TERISI + mode harian -> Bentuk C: 1 baris ringkasan +
 *     rincian_transaksi per metode, TANPA atribusi teller per baris
 *     transaksi (Skenario 4).
 *
 * DUA method publik BridgeService yang dipakai di sini punya kegunaan
 * beda dan TIDAK saling menggantikan -- TAPI pemetaannya BUKAN "1
 * method = 1 Bentuk", melainkan "1 method = 1 SUMBER DATA":
 *   - fetch()              -> breakdown per VENDOR doang, TANPA atribusi
 *     teller. Dipakai HANYA di S1/S5 (Bentuk A multi-hari).
 *   - fetchDetailPerUser() -> breakdown per TELLER (mode
 *     detail_rekonsiliasi) -- GROUP BY-nya SUPERSET dari fetch() (juga
 *     termasuk vendor), jadi vendors{} BISA diturunkan dari sini juga.
 *     Dipakai di S2/S4/S6 (Bentuk B/C, loket_id terisi) DAN S3 (Bentuk
 *     A 1-hari, loket_id NULL) -- S3 SENGAJA gak pernah manggil fetch()
 *     sama sekali, karena `drill_down_teller` (wajib ada di S3 per
 *     kontrak §5) butuh breakdown per-teller yang cuma bisa didapat dari
 *     sini, dan vendors{} yang dibutuhin toh bisa diturunin dari data
 *     yang sama (SUM lintas teller+produk) -- manggil fetch() juga di
 *     S3 cuma bakal jadi kerja dua kali buat angka yang sama.
 * Konsekuensi: ada 3 kombinasi cache key yang SEMUANYA valid dan TIDAK
 * saling collision (lihat ambilLoketWideMultiHari/
 * ambilDetailSemuaLoketHarian/ambilDetailLoket|Harian):
 *   (loket_id null,  fetch())              -> prefix 'laporan_fetch'
 *   (loket_id null,  fetchDetailPerUser()) -> prefix 'laporan_detail_semua_loket'
 *   (loket_id terisi, fetchDetailPerUser()) -> prefix 'laporan_detail'
 * Sisi SETORAN (metode_setoran: transfer/qris/tunai/kpay) SELALU dari
 * tabel lokal setoran_detail, bridge tidak tahu apa-apa soal itu.
 *
 * KEPUTUSAN DIKONFIRMASI (sebelumnya OPEN ITEM, sudah di-ACC user):
 *   1. `drill_down_teller` status_workflow untuk teller yang submit di
 *      sistem LAMA tapi belum punya row lokal sama sekali -- pakai
 *      'belum_input' sebagai placeholder, samakan pola SnapshotService.
 *      DIKONFIRMASI FINAL, bukan lagi asumsi sementara.
 */
class LaporanDataService
{
    /** Snapshot "masih hangat" (belum lewat H+2) -- boleh cepat basi. */
    private const TTL_PENDEK_DETIK = 10 * 60;

    /** Tanggal yang sudah lewat H+2 dianggap sudah final di sistem lama. */
    private const TTL_PANJANG_DETIK = 30 * 24 * 60 * 60;

    private const BATAS_H_MINUS = 2;

    private const PER_PAGE_BENTUK_B = 8;

    /**
     * Key cache buat fallback daftar vendor terakhir yang berhasil
     * diambil -- lihat daftarVendorRoot(). SENGAJA cuma 1 key global
     * (bukan per-loket/per-tanggal) karena daftar vendor itu sifatnya
     * sistem-lebar, bukan per-periode.
     */
    private const CACHE_KEY_VENDOR_ROOT_TERAKHIR = 'laporan_vendor_root_terakhir';

    public function __construct(
        private readonly BridgeService $bridgeService,
    ) {
    }

    /**
     * Satu pintu masuk. Controller cuma manggil ini, tidak pernah
     * manggil BridgeService/cache langsung -- sama seperti pola
     * SnapshotService di shared logic #4.
     *
     * $paginasi=false dipakai LaporanExportController (§A v10: "download
     * = apa yang ditampilkan", tapi file export/download HARUS berisi
     * SELURUH periode, bukan 1 halaman 8-item seperti tampilan layar) --
     * cuma berpengaruh ke Bentuk B, Bentuk A/C memang gak pernah dipaging.
     *
     * @return array{bentuk: string, ...}
     */
    public function generate(
        string $mode,
        ?int $loketId,
        string $tanggalMulai,
        string $tanggalSelesai,
        ?string $tellerId = null,
        ?User $actor = null,
        int $halaman = 1,
        bool $paginasi = true,
        bool $forceRefresh = false,
    ): array {
        return $loketId === null
            ? $this->bentukA($mode, $tanggalMulai, $tanggalSelesai, $actor, $forceRefresh)
            : ($mode === 'harian'
                ? $this->bentukC($loketId, $tanggalMulai, $tellerId, $actor, $forceRefresh)
                : $this->bentukB($loketId, $tanggalMulai, $tanggalSelesai, $tellerId, $actor, $halaman, $paginasi, $forceRefresh));
    }

    // =====================================================================
    // BENTUK A -- loket_id kosong, 1 baris per loket, span 1 periode utuh
    // =====================================================================

    private function bentukA(string $mode, string $tanggalMulai, string $tanggalSelesai, ?User $actor, bool $forceRefresh = false): array
    {
        // S3 (mode=harian) vs S1/S5 (mode=bulanan/rentang) -- BUKAN cuma
        // beda cara ambil cache, tapi beda SUMBER BRIDGE sepenuhnya.
        // REVISI: sebelumnya dideteksi dari `$tanggalMulai===$tanggalSelesai`
        // (kebetulan tanggal sama), BUKAN dari $mode -- itu salah, karena
        // FE bisa kirim mode=rentang dengan tanggal_mulai=tanggal_selesai
        // (user milih rentang 1-hari yang sama persis, edge case tapi
        // mungkin), dan itu bakal DIAM-DIAM ke-treat sebagai S3 (dapet
        // drill_down_teller) padahal kontrak §5 cuma nyebut S2/3/6, S5
        // gak termasuk walau kebetulan cuma 1 hari. Deteksi HARUS dari
        // $mode, bukan dari kebetulan lebar tanggal.
        $s3 = $mode === 'harian';

        $bridge = $s3
            ? $this->ambilDetailSemuaLoketHarian($tanggalMulai, $actor, $forceRefresh)
            : $this->ambilLoketWideMultiHari($tanggalMulai, $tanggalSelesai, $actor, $forceRefresh);

        $rowsBridge = collect($bridge['data'])->groupBy('loket_id');
        $vendorRoot = $this->daftarVendorRoot($bridge['data'], $bridge['gagal']);

        // SUM() langsung di SQL (bukan tarik SetoranHarian+entries penuh
        // ke PHP) -- lebih ringan buat periode panjang (~366 hari, banyak
        // loket) karena PHP gak perlu megang row mentah sama sekali, cuma
        // hasil agregat per loket+metode.
        $metodePerLoket = $this->agregatMetodePerLoket($tanggalMulai, $tanggalSelesai);

        // drill_down_teller CUMA applicable di S3 (1 hari -- setiap baris
        // loket otomatis "per tanggal" juga karena tanggalnya cuma 1).
        // Di S1/S5 (multi-hari, 1 baris = rentang banyak hari) field ini
        // TIDAK APPLICABLE, dibiarkan null -- bukan array kosong, biar
        // beda jelas dari "applicable tapi kebetulan nihil".
        $drillDownPerLoket = null;
        if ($s3) {
            // 1 query batched buat SEMUA loket sekaligus (bukan per-loket)
            // -- konsisten sama standar N+1 yang udah dipegang di Bentuk
            // B. TANPA eager-load `entries` -- drillDownTeller() gak
            // butuh itu.
            $setoranPerLoket = SetoranHarian::where('status', 'submitted')
                ->where('tanggal_transaksi', $tanggalMulai)
                ->with('user:id,nama,user_id_sopp')
                ->get()
                ->groupBy('loket_id');

            $drillDownPerLoket = $rowsBridge->keys()->merge($setoranPerLoket->keys())->unique()
                ->mapWithKeys(fn ($loketId) => [
                    $loketId => $this->drillDownTeller($setoranPerLoket->get($loketId, collect()), $rowsBridge->get($loketId, collect())),
                ]);
        }

        // Loket dinamis: cuma yang ADA transaksi (bridge ATAU setoran
        // lokal) di periode ini yang tampil -- bukan semua row tabel
        // `lokets`.
        $loketIdRelevan = $rowsBridge->keys()->merge(array_keys($metodePerLoket))->unique()->sort()->values();
        $lokets = Loket::whereIn('loket_id', $loketIdRelevan)->get()->keyBy('loket_id');

        $items = $loketIdRelevan->map(function (int $loketId) use ($lokets, $rowsBridge, $metodePerLoket, $vendorRoot, $bridge, $drillDownPerLoket) {
            $metode = $metodePerLoket[$loketId] ?? ['transfer' => 0, 'qris' => 0, 'tunai' => 0, 'kpay' => 0];
            $totalSetor = array_sum($metode);

            // Kalau bridge gagal, vendors JUGA di-null-in (BUKAN cuma
            // total_pendapatan_sopp) -- sebelumnya vendors tetap tampil
            // angka dari data PARTIAL yang kebetulan berhasil, kelihatan
            // kayak angka final padahal cuma sebagian periode. Laporan
            // finansial: baris yang gak lengkap gak boleh kelihatan
            // lengkap di kolom manapun.
            $vendors = $bridge['gagal'] ? null : $this->sumPerVendor($rowsBridge->get($loketId, collect()), $vendorRoot);
            $totalPendapatanSopp = $vendors === null ? null : array_sum($vendors);
            $selisih = $totalPendapatanSopp !== null ? $totalPendapatanSopp - $totalSetor : null;

            return [
                'loket_id' => $loketId,
                'nama_loket' => $lokets->get($loketId)?->nama_loket,
                'vendors' => $vendors,
                'metode_setoran' => $metode,
                'total_pendapatan_sopp' => $totalPendapatanSopp,
                'total_setor' => $totalSetor,
                'selisih' => $selisih,
                'status_keuangan' => $this->statusKeuangan($selisih),
                'drill_down_teller' => $drillDownPerLoket?->get($loketId, []),
            ];
        })->values()->all();

        return [
            'bentuk' => 'A',
            'vendors' => $vendorRoot,
            'bridge_gagal' => $bridge['gagal'],
            'items' => $items,
            'total_row' => $this->totalRow($items, $vendorRoot),
        ];
    }

    // =====================================================================
    // BENTUK B -- loket_id terisi, mode bulanan/rentang, 1 baris/tanggal
    // =====================================================================

    private function bentukB(int $loketId, string $tanggalMulai, string $tanggalSelesai, ?string $tellerId, ?User $actor, int $halaman, bool $paginasi = true, bool $forceRefresh = false): array
    {
        $bridge = $this->ambilDetailLoket($loketId, $tanggalMulai, $tanggalSelesai, $actor, $forceRefresh);
        $rowsPerTanggal = collect($bridge['data'])->groupBy('tanggal');
        $vendorRoot = $this->daftarVendorRoot($bridge['data'], ! empty($bridge['hari_gagal']));

        $tanggalList = $this->rentangTanggal($tanggalMulai, $tanggalSelesai);

        // SATU query buat SELURUH periode (pola sama kayak data bridge di
        // atas), bukan 1 query per hari -- periode bisa sampai ~366 hari,
        // jadi query-per-hari di sini dulu (plus 1 lagi di dalam
        // drillDownTeller pakai filter yang nyaris sama) berarti sampai
        // ~732 query buat 1 request. `entries`+`user` di-eager-load
        // sekalian, lalu dikelompokkan per tanggal DI MEMORI.
        $setoranLokalPerTanggal = SetoranHarian::where('loket_id', $loketId)
            ->where('status', 'submitted')
            ->whereBetween('tanggal_transaksi', [$tanggalMulai, $tanggalSelesai])
            ->with(['entries', 'user:id,nama,user_id_sopp'])
            ->get()
            ->groupBy(fn (SetoranHarian $s) => Carbon::parse($s->tanggal_transaksi)->toDateString());

        $items = collect($tanggalList)->map(function (string $tanggal) use ($rowsPerTanggal, $vendorRoot, $tellerId, $bridge, $setoranLokalPerTanggal) {
            $rowsBridgeHariIniAsli = $rowsPerTanggal->get($tanggal, collect());
            $rowsHariIni = $tellerId !== null
                ? $rowsBridgeHariIniAsli->filter(fn (array $r) => $r['user_id'] === $tellerId)
                : $rowsBridgeHariIniAsli;

            $hariIniGagal = in_array($tanggal, $bridge['hari_gagal'], true);

            $setoranHariIniAsli = $setoranLokalPerTanggal->get($tanggal, collect());
            $setoranHariIni = $tellerId !== null
                ? $setoranHariIniAsli->filter(fn (SetoranHarian $s) => $s->user?->user_id_sopp === $tellerId)
                : $setoranHariIniAsli;

            // Hari kosong (bridge sukses, tanpa transaksi bridge DAN tanpa
            // setoran submitted) tidak ditampilkan. Hari bridge GAGAL tetap
            // tampil (itu "tidak diketahui", bukan "kosong"). Hari ada
            // transaksi tapi belum disetor juga tetap tampil.
            //
            // Dicek DI SINI (sebelum forPage() di bawah) supaya
            // total_items/total_pages dan isi tiap halaman ikut benar,
            // dan export (paginasi=false) otomatis bersih juga.
            if (! $hariIniGagal && $rowsHariIni->isEmpty() && $setoranHariIni->isEmpty()) {
                return null;
            }

            // Sama kayak Bentuk A: vendors JUGA di-null-in kalau HARI INI
            // gagal (granularitas per-tanggal, bukan per-periode -- beda
            // dari Bentuk A karena di sini 1 baris = 1 hari, jadi kegagalan
            // 1 hari cukup nge-null baris itu saja, gak perlu nge-null
            // baris tanggal lain yang berhasil).
            $vendors = $hariIniGagal ? null : $this->sumPerVendor($rowsHariIni, $vendorRoot);
            $totalPendapatanSopp = $vendors === null ? null : array_sum($vendors);

            $metode = $this->sumPerMetode($setoranHariIni->flatMap->entries);
            $totalSetor = array_sum($metode);
            $selisih = $totalPendapatanSopp !== null ? $totalPendapatanSopp - $totalSetor : null;

            return [
                'tanggal' => $tanggal,
                'vendors' => $vendors,
                'metode_setoran' => $metode,
                'total_pendapatan_sopp' => $totalPendapatanSopp,
                'total_setor' => $totalSetor,
                'selisih' => $selisih,
                'status_keuangan' => $this->statusKeuangan($selisih),
                // KOREKSI dari draf sebelumnya: field ini HARUS pakai versi
                // TERFILTER ($setoranHariIni/$rowsHariIni), BUKAN versi
                // Asli. Draf sebelumnya sengaja pakai versi Asli dengan
                // asumsi "biar user lihat konteks penuh" -- ternyata itu
                // KONTRADIKSI sama Kontrak-API-Laporan-untuk-FE.md §5,
                // yang eksplisit bilang field ini "tetap muncul walau
                // filter teller_id aktif -- otomatis collapse jadi 0 atau
                // 1 elemen saja (karena datanya sudah di-scope ke 1
                // orang)". Collapse itu cuma kejadian kalau datanya emang
                // sudah difilter sebelum masuk sini -- versi Asli gak
                // akan pernah collapse. Pelajaran: keputusan "produk" yang
                // ditulis sendiri di komentar kode itu perlu dicek balik
                // ke kontrak yang sudah disepakati ke FE, bukan diasumsikan.
                'drill_down_teller' => $this->drillDownTeller($setoranHariIni, $rowsHariIni),
            ];
        })->filter()->values();

        // total_row & teller_tersedia/teller_tanpa_atribusi_bridge SELALU
        // dihitung dari SELURUH periode (items belum dipaging), bukan
        // cuma yang tampil di 1 halaman -- kalau dihitung dari hasil
        // forPage() bakal salah tiap kali user pindah halaman. Sekarang
        // ini murni operasi in-memory (data lokal+bridge sudah ditarik
        // sekali di atas), jadi paging TIDAK bikin ini lebih murah maupun
        // lebih mahal -- costnya sudah lunas di awal, bukan per-halaman.
        // total_row tidak terpengaruh pembuangan hari kosong, karena hari
        // kosong nilainya memang 0 di semua kolom.
        $totalRow = $this->totalRow($items->all(), $vendorRoot);
        [$tellerTersedia, $tellerTanpaAtribusi] = $this->daftarTeller($setoranLokalPerTanggal->flatten(1), $bridge['data']);

        $itemsHalamanIni = $paginasi
            ? $items->forPage($halaman, self::PER_PAGE_BENTUK_B)->values()->all()
            : $items->all();

        return [
            'bentuk' => 'B',
            'nama_loket' => Loket::find($loketId)?->nama_loket,
            'vendors' => $vendorRoot,
            'bridge_gagal' => ! empty($bridge['hari_gagal']),
            'items' => $itemsHalamanIni,
            'total_row' => $totalRow,
            'teller_tersedia' => $tellerTersedia,
            'teller_tanpa_atribusi_bridge' => $tellerTanpaAtribusi,
            // null kalau $paginasi=false (export/download) -- gak ada
            // "halaman" yang relevan buat file yang isinya seluruh
            // periode sekaligus.
            //
            // Nama key `meta` (bukan `pagination`) dan field di dalamnya
            // (current_page/per_page/total_items/total_pages, bukan
            // halaman/per_halaman/total_item) DISAMAKAN dengan
            // LogAktivitasController & RiwayatSetoranController -- 2
            // endpoint lain di project yang SAMA sudah pakai bentuk ini
            // persis (termasuk formula total_pages), Laporan sempat
            // sendirian pakai bentuk beda. total_pages sebelumnya malah
            // gak pernah dihitung sama sekali (bukan cuma beda nama).
            'meta' => $paginasi ? [
                'current_page' => $halaman,
                'per_page' => self::PER_PAGE_BENTUK_B,
                'total_items' => $items->count(),
                'total_pages' => max((int) ceil($items->count() / self::PER_PAGE_BENTUK_B), 1),
            ] : null,
        ];
    }

    // =====================================================================
    // BENTUK C -- loket_id terisi + mode harian, 1 hari, expand penuh
    // =====================================================================

    private function bentukC(int $loketId, string $tanggal, ?string $tellerId, ?User $actor, bool $forceRefresh = false): array
    {
        $bridge = $this->ambilDetailLoketHarian($loketId, $tanggal, $actor, $forceRefresh);
        $rowsHariIni = collect($bridge['data']);
        if ($tellerId !== null) {
            $rowsHariIni = $rowsHariIni->filter(fn (array $r) => $r['user_id'] === $tellerId);
        }
        $vendorRoot = $this->daftarVendorRoot($bridge['data'], $bridge['gagal']);

        // Sama kayak Bentuk A/B: vendors JUGA di-null-in kalau bridge
        // gagal. Di Bentuk C efeknya lebih halus (1 hari doang, jadi
        // rowsHariIni otomatis kosong kalau bridge gagal, sumPerVendor
        // bakal balikin semua-0 bukan angka partial yang meyakinkan) --
        // tapi tetap dibenerin biar konsisten & FE gak perlu beda logic
        // per bentuk buat baca field yang sama.
        $vendors = $bridge['gagal'] ? null : $this->sumPerVendor($rowsHariIni, $vendorRoot);
        $totalPendapatanSopp = $vendors === null ? null : array_sum($vendors);

        // 1 hari doang di sini, jadi bukan soal N+1 -- tapi tetap ditarik
        // SEKALI TANPA filter teller lalu difilter DI MEMORI (bukan 2
        // query beda-filter) supaya konsisten sama pola Bentuk B, dan
        // collection yang sama (tanpa filter) bisa dipakai ulang buat
        // daftarTeller() di bawah.
        $semuaSetoranHariIni = SetoranHarian::where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->where('status', 'submitted')
            ->with(['entries', 'user:id,nama,user_id_sopp'])
            ->get();

        $setoranTerfilter = $tellerId !== null
            ? $semuaSetoranHariIni->filter(fn (SetoranHarian $s) => $s->user?->user_id_sopp === $tellerId)
            : $semuaSetoranHariIni;

        $entries = $setoranTerfilter->flatMap->entries;
        $metode = $this->sumPerMetode($entries);
        $totalSetor = array_sum($metode);
        $selisih = $totalPendapatanSopp !== null ? $totalPendapatanSopp - $totalSetor : null;

        // rincian_transaksi: dikelompokkan per metode SAJA, TANPA atribusi
        // teller per baris (keputusan final Skenario 4) -- reuse
        // SetoranDetail::toApiShape() (shared logic) biar foto_bukti_url
        // dst konsisten dengan Card A/Detail Setoran, bukan didefinisikan
        // ulang di sini.
        $rincianTransaksi = collect(['transfer', 'qris', 'tunai', 'kpay'])
            ->mapWithKeys(fn (string $m) => [
                $m => $entries->where('metode_pembayaran', $m)
                    ->map(fn (SetoranDetail $e) => $e->toApiShape())
                    ->values()->all(),
            ])->all();

        [$tellerTersedia, $tellerTanpaAtribusi] = $this->daftarTeller($semuaSetoranHariIni, $bridge['data']);

        return [
            'bentuk' => 'C',
            'nama_loket' => Loket::find($loketId)?->nama_loket,
            'tanggal' => $tanggal,
            'vendors' => $vendors,
            'metode_setoran' => $metode,
            'total_pendapatan_sopp' => $totalPendapatanSopp,
            'total_setor' => $totalSetor,
            'selisih' => $selisih,
            'status_keuangan' => $this->statusKeuangan($selisih),
            'bridge_gagal' => $bridge['gagal'],
            'rincian_transaksi' => $rincianTransaksi,
            'teller_tersedia' => $tellerTersedia,
            'teller_tanpa_atribusi_bridge' => $tellerTanpaAtribusi,
        ];
    }

    // =====================================================================
    // Teller tersedia / tanpa atribusi bridge / drill-down
    // (dipakai bareng Bentuk B & C)
    // =====================================================================

    /**
     * Terima collection `SetoranHarian` (relasi `user` HARUS sudah
     * eager-loaded oleh pemanggil) yang sudah ditarik sekali buat
     * periode/hari terkait -- method ini TIDAK query DB sendiri, supaya
     * gampang dipastikan tidak jadi sumber N+1 baru di pemanggil manapun.
     *
     * @param  Collection<int, SetoranHarian>  $setoranSeluruhPeriode
     * teller_id di sini STRING (bukan integer) -- id legacy sistem lama
     * (user_id_sopp), disamakan sama tipe parameter filter `$tellerId`
     * di seluruh method lain di kelas ini.
     *
     * @return array{0: list<array{teller_id:string,nama:?string}>, 1: list<array{teller_id:string,nama:?string}>}
     */
    private function daftarTeller(Collection $setoranSeluruhPeriode, array $rowsBridgeFull): array
    {
        // uniqueStrict/containsStrict SENGAJA (bukan unique()/contains()
        // default) -- keduanya default ke perbandingan LONGGAR (==), dan
        // teller_id/user_id_sopp itu string ID legacy yang berpotensi
        // leading-zero bermakna ("007" vs "7") -- kelas bug yang sama
        // kayak alasan teller_id diketikkan string, bukan int (lihat A1).
        // PHP longgar bakal anggap "007"==="7" true dan bisa keliru
        // nyamain 2 teller beda jadi 1.
        $idSoppDiBridge = collect($rowsBridgeFull)->pluck('user_id')->filter()->uniqueStrict();

        $tellerTersedia = collect($rowsBridgeFull)
            ->filter(fn ($r) => $r['user_id'] !== null)
            ->uniqueStrict('user_id')
            ->map(fn ($r) => ['teller_id' => $r['user_id'], 'nama' => $r['user_name'] ?? null])
            ->values()->all();

        $tellerTanpaAtribusi = $setoranSeluruhPeriode
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $u->user_id_sopp !== null && ! $idSoppDiBridge->containsStrict($u->user_id_sopp))
            ->map(fn (User $u) => ['teller_id' => $u->user_id_sopp, 'nama' => $u->nama])
            ->values()->all();

        return [$tellerTersedia, $tellerTanpaAtribusi];
    }

    /**
     * Sama seperti `daftarTeller()` -- terima collection yang sudah
     * ditarik pemanggil (relasi `user` sudah eager-loaded), TIDAK query
     * DB sendiri.
     *
     * PUBLIC (sebelumnya private) -- direuse DashboardAdminController
     * (bagian 6b/6c) buat kebutuhan yang sama persis: gabungan baris
     * lokal + nama dari bridge yang belum punya row lokal, supaya dua
     * fitur ini gak punya 2 jalur kode beda buat data yang sama
     * (lihat bagian 0 dokumen desain Dashboard Admin).
     *
     * `status_workflow` sekarang diambil dari `$s->status` asli
     * (sebelumnya hardcode 'submitted') -- AMAN buat Laporan karena
     * $setoranHariIni yang dikirim ke sini dari Laporan SELALU
     * hasil query where('status','submitted') duluan, jadi $s->status
     * di situ pasti 'submitted' juga, outputnya identik seperti
     * sebelumnya. Perubahan ini cuma bikin method-nya general buat
     * pemanggil lain (Dashboard Admin) yang butuh baris draft ikut
     * kebawa juga.
     *
     * @param  Collection<int, SetoranHarian>  $setoranHariIni
     * @param  Collection<int, array>  $rowsBridgeHariIni
     * @return list<array{nama:?string, setoran_id:?int, status_workflow:string}>
     */
    public function drillDownTeller(Collection $setoranHariIni, Collection $rowsBridgeHariIni): array
    {
        $hasil = $setoranHariIni->map(fn (SetoranHarian $s) => [
            'nama' => $s->user->nama ?? null,
            'setoran_id' => $s->id,
            'status_workflow' => $s->status,
        ]);

        $idSoppSudahAda = $setoranHariIni->pluck('user.user_id_sopp')->filter();

        // uniqueStrict/containsStrict -- alasan sama kayak daftarTeller().
        // Teller yang submit di sistem LAMA tapi belum punya row lokal
        // sama sekali di tanggal ini -- OPEN ITEM #1, lihat docblock kelas.
        foreach ($rowsBridgeHariIni->uniqueStrict('user_id') as $row) {
            if ($row['user_id'] !== null && ! $idSoppSudahAda->containsStrict($row['user_id'])) {
                $hasil->push([
                    'nama' => $row['user_name'] ?? null,
                    'setoran_id' => null,
                    'status_workflow' => 'belum_input',
                ]);
            }
        }

        return $hasil->values()->all();
    }

    // =====================================================================
    // Cache layer (§C) -- SATU-SATUNYA tempat yang tau TTL H+2 & bagaimana
    // bridge dipanggil buat kebutuhan Laporan. Bentuk A/B/C di atas semua
    // manggil lewat sini, tidak pernah ke BridgeService langsung.
    // =====================================================================

    /**
     * Dipakai Bentuk A, KHUSUS S1/S5 (multi-hari, loket_id null). S3
     * (1 hari, loket_id null) TIDAK lewat sini lagi -- lihat
     * ambilDetailSemuaLoketHarian() dan docblock kelas bagian "S3 1-call"
     * kenapa S3 punya sumber bridge yang beda sepenuhnya dari S1/S5.
     *
     * @return array{data: array, gagal: bool}
     */
    private function ambilLoketWideMultiHari(string $tanggalMulai, string $tanggalSelesai, ?User $actor, bool $forceRefresh = false): array
    {
        $perHari = $this->ambilPerHariIndividual(
            fn ($m, $s) => $this->bridgeService->fetch($m, $s, null, $actor),
            'laporan_fetch',
            null,
            $tanggalMulai,
            $tanggalSelesai,
            $forceRefresh,
        );

        return [
            'data' => collect($perHari['data_per_hari'])->flatten(1)->all(),
            'gagal' => ! empty($perHari['hari_gagal']),
        ];
    }

    /**
     * Dipakai Bentuk A KHUSUS S3 (1 hari, loket_id null) -- SATU-SATUNYA
     * sumber bridge buat S3, `fetch()` TIDAK dipanggil sama sekali di
     * jalur ini. Alasan (bukan cuma efisiensi -- ini fakta matematis):
     * GROUP BY di mode `detail_rekonsiliasi` bridge.php
     * (tanggal+loket+user+vendor+produk) adalah SUPERSET dari GROUP BY
     * mode default (tanggal+loket+vendor), dari tabel & WHERE yang
     * identik -- SUM lintas user+produk dari hasil detail WAJIB sama
     * dengan hasil fetch() biasa. `vendors{}` diturunkan dari sini via
     * sumPerVendor() (reuse persis dari Bentuk B/C), `drill_down_teller`
     * juga dari sini via drillDownTeller() (reuse persis) -- 1 call
     * bridge cukup buat dua-duanya, gak perlu fetch() terpisah lagi.
     *
     * $actor diteruskan (beda dari sebelumnya) -- BridgeService::
     * fetchDetailPerUser() sekarang ikut trigger deteksi vendor baru,
     * supaya S3 gak jadi satu-satunya jalur yang gak pernah ke-deteksi.
     *
     * Cache key prefix 'laporan_detail_semua_loket' -- NAMESPACE KE-3,
     * beda dari 'laporan_fetch' (S1/S5, sama-sama loket_id null tapi
     * sumber fetch()) dan 'laporan_detail' (S2/S4/S6, loket_id SELALU
     * terisi). 3 prefix ini gak pernah saling collision.
     *
     * PUBLIC (sebelumnya private) -- direuse DashboardAdminController
     * buat total_pendapatan_sopp per loket (bagian 3 dokumen desainnya).
     * Dashboard Admin & Laporan (mode=harian) sekarang SELALU baca dari
     * cache key yang SAMA PERSIS buat tanggal yang sama -- jaminan
     * angkanya gak pernah beda antara dua halaman ini, bukan cuma
     * "logic-nya mirip".
     *
     * @return array{data: array, gagal: bool}
     */
    public function ambilDetailSemuaLoketHarian(string $tanggal, ?User $actor, bool $forceRefresh = false): array
    {
        $this->pastikanNamespaceCacheValid('laporan_detail_semua_loket', null);

        return $this->ambilPerRentangPersis(
            fn ($m, $s) => $this->bridgeService->fetchDetailPerUser($m, $s, null, $actor),
            "laporan_detail_semua_loket:{$tanggal}:{$tanggal}",
            $tanggal,
            $forceRefresh,
        );
    }

    /**
     * Dipakai Bentuk B (rentang banyak hari, loket_id terisi). $actor
     * diteruskan (beda dari sebelumnya) -- lihat alasan sama kayak
     * ambilDetailSemuaLoketHarian() di atas soal deteksi vendor baru.
     *
     * @return array{data: array, hari_gagal: list<string>}
     */
    private function ambilDetailLoket(int $loketId, string $tanggalMulai, string $tanggalSelesai, ?User $actor, bool $forceRefresh = false): array
    {
        $perHari = $this->ambilPerHariIndividual(
            fn ($m, $s) => $this->bridgeService->fetchDetailPerUser($m, $s, $loketId, $actor),
            'laporan_detail',
            $loketId,
            $tanggalMulai,
            $tanggalSelesai,
            $forceRefresh,
        );

        return [
            'data' => collect($perHari['data_per_hari'])->flatten(1)->all(),
            'hari_gagal' => $perHari['hari_gagal'],
        ];
    }

    /**
     * Dipakai Bentuk C (persis 1 hari, loket_id terisi). $actor
     * diteruskan (beda dari sebelumnya) -- alasan sama kayak di atas.
     *
     * @return array{data: array, gagal: bool}
     */
    private function ambilDetailLoketHarian(int $loketId, string $tanggal, ?User $actor, bool $forceRefresh = false): array
    {
        $this->pastikanNamespaceCacheValid('laporan_detail', $loketId);

        return $this->ambilPerRentangPersis(
            fn ($m, $s) => $this->bridgeService->fetchDetailPerUser($m, $s, $loketId, $actor),
            "laporan_detail:{$loketId}:{$tanggal}:{$tanggal}",
            $tanggal,
            $forceRefresh,
        );
    }

    /**
     * Kelompok cache #1: per-RENTANG-PERSIS. Cocok buat mode harian
     * (rentang 1 hari) -- hit/miss-nya dicek utuh sebagai satu unit,
     * bukan dipecah per-hari, karena memang cuma 1 hari.
     *
     * Cache HANYA ditulis kalau fetch sukses (pola sama seperti
     * SnapshotService::tanggalBridgeUntukLoket()) -- kegagalan tidak
     * boleh ikut ke-cache supaya masih ada percobaan retry dalam TTL.
     *
     * $forceRefresh: pola SAMA PERSIS kayak SnapshotService::
     * getForLoketTanggal() -- kalau true, cache yang ADA di-skip total
     * (dianggap miss), TAPI hasil fetch yang baru TETAP ditulis ulang ke
     * cache (bukan cuma dipakai sekali) -- jadi TTL ke-reset juga buat
     * pembaca berikutnya, bukan cuma buat request yang mijit refresh.
     *
     * @return array{data: array, gagal: bool}
     */
    private function ambilPerRentangPersis(callable $fetcher, string $cacheKey, string $tanggalUntukTtl, bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return ['data' => $cached, 'gagal' => false];
            }
        }

        try {
            $hasil = $fetcher($tanggalUntukTtl, $tanggalUntukTtl);
            Cache::put($cacheKey, $hasil['data'], $this->ttlDetikUntukTanggal($tanggalUntukTtl));

            return ['data' => $hasil['data'], 'gagal' => false];
        } catch (BridgeException $e) {
            return ['data' => [], 'gagal' => true];
        }
    }

    /**
     * Kelompok cache #2: per-HARI-INDIVIDUAL. Dipakai buat mode
     * bulanan/rentang -- tiap tanggal dalam periode dicek cache-nya
     * SENDIRI-SENDIRI (supaya hari yang sudah pernah dibuka di laporan
     * lain, walau periodenya beda, tetap kena cache-hit).
     *
     * Hari-hari yang cache-miss digabung jadi run tanggal BERURUTAN
     * seminimal mungkin, LALU dipecah lagi jadi chunk fisik ≤31 hari
     * (pakai BridgeService::MAKS_HARI_PER_REQUEST, bukan angka "31"
     * terpisah) SEBELUM dipanggil ke bridge -- REVISI: draf sebelumnya
     * cuma andalin BridgeService yang mecah >31 hari di baliknya, tapi
     * itu bikin try/catch di sini cuma bisa presisi per-RUN, bukan
     * per-chunk-fisik (karena fetch()/fetchDetailPerUser() atomik dari
     * sudut pandang pemanggil -- gagal 1 chunk internal = seluruh method
     * throw, gak ada partial result yang bisa diselamatkan dari situ).
     * Dengan mecah SENDIRI di sini dulu, tiap panggilan ke $fetcher()
     * dijamin PAS 1 chunk fisik, jadi try/catch presisi per-chunk:
     * kegagalan 1 chunk gak lagi ikut menandai gagal hari-hari di chunk
     * lain dalam run yang sama yang sebenarnya sukses.
     *
     * $forceRefresh: sama kayak ambilPerRentangPersis() -- Cache::many()
     * di-skip total (SEMUA tanggal dalam periode dianggap miss, gak
     * cuma yang kebetulan belum ke-cache), tapi hasil fetch tetap
     * ditulis ulang per-hari (reset TTL) di bawah, gak ada logic baru
     * di jalur write-nya.
     *
     * @return array{data_per_hari: array<string, array>, hari_gagal: list<string>}
     */
    private function ambilPerHariIndividual(callable $fetcher, string $prefix, ?int $loketId, string $tanggalMulai, string $tanggalSelesai, bool $forceRefresh = false): array
    {
        $semuaTanggal = $this->rentangTanggal($tanggalMulai, $tanggalSelesai);

        $dataPerHari = [];
        $hariMiss = $semuaTanggal;

        if (! $forceRefresh) {
            // Cache::many() -- SATU query `WHERE IN` di driver `database`
            // (dikonfirmasi dari CACHE_STORE=.env + baca langsung
            // DatabaseStore::many() di vendor), bukan 1 query per hari
            // kayak Cache::get() dipanggil di loop. Sama-sama berlaku di
            // Redis (native MGET) kalau driver-nya diganti nanti.
            $keyPerTanggal = collect($semuaTanggal)->mapWithKeys(
                fn (string $tanggal) => [$tanggal => $this->cacheKeyHarian($prefix, $loketId, $tanggal)]
            );
            $cachedValues = Cache::many($keyPerTanggal->values()->all());

            $hariMiss = [];
            foreach ($semuaTanggal as $tanggal) {
                $cached = $cachedValues[$keyPerTanggal[$tanggal]] ?? null;
                if ($cached !== null) {
                    $dataPerHari[$tanggal] = $cached;
                } else {
                    $hariMiss[] = $tanggal;
                }
            }
        }

        $hariGagal = [];

        foreach ($this->kelompokkanHariBerurutan($hariMiss) as [$mulaiRun, $selesaiRun]) {
            foreach ($this->pecahRunJadiChunkFisik($mulaiRun, $selesaiRun) as [$mulaiChunk, $selesaiChunk]) {
                try {
                    $hasil = $fetcher($mulaiChunk, $selesaiChunk);
                    $rowsPerTanggalChunk = collect($hasil['data'])->groupBy('tanggal');

                    foreach ($this->rentangTanggal($mulaiChunk, $selesaiChunk) as $tanggal) {
                        $rowsHariIni = $rowsPerTanggalChunk->get($tanggal, collect())->values()->all();
                        $dataPerHari[$tanggal] = $rowsHariIni;
                        Cache::put(
                            $this->cacheKeyHarian($prefix, $loketId, $tanggal),
                            $rowsHariIni,
                            $this->ttlDetikUntukTanggal($tanggal),
                        );
                    }
                } catch (BridgeException $e) {
                    foreach ($this->rentangTanggal($mulaiChunk, $selesaiChunk) as $tanggal) {
                        $dataPerHari[$tanggal] = [];
                        $hariGagal[] = $tanggal;
                    }
                }
            }
        }

        return ['data_per_hari' => $dataPerHari, 'hari_gagal' => $hariGagal];
    }

    /**
     * Pecah 1 run (bisa berapapun panjangnya) jadi chunk ≤31 hari, pakai
     * limit yang SAMA persis kayak BridgeService::MAKS_HARI_PER_REQUEST
     * (bukan angka "31" terpisah) -- lihat penjelasan lengkap di
     * ambilPerHariIndividual() kenapa ini perlu dipecah DI SINI, bukan
     * cuma diandalkan dari pemecahan internal BridgeService.
     *
     * @return list<array{0:string,1:string}> pasangan [mulai,selesai] per chunk fisik
     */
    private function pecahRunJadiChunkFisik(string $mulai, string $selesai): array
    {
        $kursor = Carbon::parse($mulai)->startOfDay();
        $akhir = Carbon::parse($selesai)->startOfDay();

        $chunks = [];
        while ($kursor->lessThanOrEqualTo($akhir)) {
            $akhirChunk = $kursor->copy()->addDays(BridgeService::MAKS_HARI_PER_REQUEST - 1);
            if ($akhirChunk->greaterThan($akhir)) {
                $akhirChunk = $akhir->copy();
            }

            $chunks[] = [$kursor->toDateString(), $akhirChunk->toDateString()];
            $kursor = $akhirChunk->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * Guard invarian 3-namespace SS C v10 -- SATU tabel yang dipakai di
     * SEMUA titik pembuatan cache key (cacheKeyHarian() DAN 2 titik key
     * literal di ambilDetailSemuaLoketHarian()/ambilDetailLoketHarian()),
     * bukan cuma yang lewat ambilPerHariIndividual(). REVISI: sebelumnya
     * guard cuma ada di cacheKeyHarian() -- itu nutupin S1/S5/S2/S6,
     * tapi S3/S4 bangun key-nya sebagai string literal langsung dan gak
     * pernah lewat guard sama sekali. Type-hint `int $loketId` (bukan
     * `?int`) di ambilDetailLoketHarian() emang udah mencegah SEBAGIAN
     * kesalahan (loket_id null gak mungkin lolos ke situ, PHP TypeError
     * duluan), tapi itu gak nutup kesalahan PREFIX (misal ke-copy-paste
     * prefix yang salah) -- makanya tetap butuh guard eksplisit di sini,
     * dipanggil dari ketiga titik.
     */
    private function pastikanNamespaceCacheValid(string $prefix, ?int $loketId): void
    {
        $wajibLoketIdTerisi = [
            'laporan_fetch' => false,
            'laporan_detail_semua_loket' => false,
            'laporan_detail' => true,
        ];

        if (! array_key_exists($prefix, $wajibLoketIdTerisi)) {
            throw new InvalidArgumentException("Prefix cache Laporan tidak dikenal: '{$prefix}'.");
        }

        if ($wajibLoketIdTerisi[$prefix] !== ($loketId !== null)) {
            throw new InvalidArgumentException(
                "Kombinasi prefix cache '{$prefix}' dan loket_id (".($loketId ?? 'null').") melanggar invarian 3-namespace: "
                ."'laporan_fetch' & 'laporan_detail_semua_loket' harus loket_id null, 'laporan_detail' harus loket_id terisi."
            );
        }
    }

    private function cacheKeyHarian(string $prefix, ?int $loketId, string $tanggal): string
    {
        $this->pastikanNamespaceCacheValid($prefix, $loketId);

        return $loketId !== null
            ? "{$prefix}:{$loketId}:{$tanggal}"
            : "{$prefix}:{$tanggal}";
    }

    /**
     * H+2 ke belakang dianggap sudah final di sistem lama (kecil
     * kemungkinan berubah lagi) -- boleh di-cache lama (30 hari).
     * Selain itu (H, H-1, H-2 belum lewat) masih bisa berubah kapan
     * saja -- TTL pendek (10 menit), sama seperti SnapshotService.
     */
    private function ttlDetikUntukTanggal(string $tanggal): int
    {
        return Carbon::parse($tanggal)->lessThanOrEqualTo(now()->subDays(self::BATAS_H_MINUS)->startOfDay())
            ? self::TTL_PANJANG_DETIK
            : self::TTL_PENDEK_DETIK;
    }

    /**
     * @return list<array{0:string,1:string}> pasangan [mulai, selesai] per run tanggal berurutan
     */
    private function kelompokkanHariBerurutan(array $tanggalList): array
    {
        if (empty($tanggalList)) {
            return [];
        }

        sort($tanggalList);

        $runs = [];
        $awal = $tanggalList[0];
        $prev = $tanggalList[0];

        foreach (array_slice($tanggalList, 1) as $tanggal) {
            if (Carbon::parse($tanggal)->equalTo(Carbon::parse($prev)->addDay())) {
                $prev = $tanggal;
                continue;
            }
            $runs[] = [$awal, $prev];
            $awal = $tanggal;
            $prev = $tanggal;
        }
        $runs[] = [$awal, $prev];

        return $runs;
    }

    /**
     * @return list<string>
     */
    private function rentangTanggal(string $mulai, string $selesai): array
    {
        return collect(CarbonPeriod::create($mulai, $selesai))
            ->map(fn (Carbon $d) => $d->toDateString())
            ->all();
    }

    // =====================================================================
    // Helper murni (vendor/metode/total) -- tidak menyentuh bridge/DB
    // =====================================================================

    /**
     * Vendor dinamis PENUH, tanpa grouping/bucket apapun -- key-nya
     * literal `vendor_name` yang muncul di data, bukan enum tetap
     * (Finnet/PUSKUD/JNE cuma contoh yang KEBETULAN ada sekarang).
     *
     * $gagal: kalau true DAN hasil dari $rowsBridge kosong, fallback ke
     * daftar vendor terakhir yang berhasil diambil (di-cache forever,
     * di-update tiap kali ada hasil non-kosong) -- BUKAN buat nampilin
     * angka (itu urusan vendors:null per-item, lihat bentukA/B/C),
     * tapi supaya STRUKTUR KOLOM tabel (nama-nama vendor) gak hilang
     * total pas bridge down total. Kalau outage kebetulan hari
     * pertama sistem berjalan (belum pernah ada cache), fallback-nya
     * ke [] juga -- gak ada cara lain, wajar.
     *
     * @return list<string>
     */
    private function daftarVendorRoot(array $rowsBridge, bool $gagal = false): array
    {
        $vendorRoot = collect($rowsBridge)->pluck('vendor_name')->filter()->unique()->sort()->values()->all();

        if ($vendorRoot !== []) {
            Cache::forever(self::CACHE_KEY_VENDOR_ROOT_TERAKHIR, $vendorRoot);

            return $vendorRoot;
        }

        return $gagal ? Cache::get(self::CACHE_KEY_VENDOR_ROOT_TERAKHIR, []) : $vendorRoot;
    }

    /**
     * @param  Collection<int, array>  $rowsBridge
     * @param  list<string>  $vendorRoot
     * @return array<string, int> -- SELALU lengkap semua key $vendorRoot, nihil = 0 (tidak sparse)
     */
    private function sumPerVendor(Collection $rowsBridge, array $vendorRoot): array
    {
        $perVendor = array_fill_keys($vendorRoot, 0);

        foreach ($rowsBridge as $row) {
            if (isset($perVendor[$row['vendor_name']])) {
                $perVendor[$row['vendor_name']] += (int) $row['total'];
            }
        }

        return $perVendor;
    }

    /**
     * Agregasi metode_setoran Bentuk A -- SUM langsung di SQL (groupBy
     * loket_id+metode_pembayaran), BUKAN tarik semua SetoranDetail ke
     * PHP dulu buat dijumlah -- Bentuk A cuma butuh angka totalnya per
     * loket, gak pernah butuh baris individual (beda dari Bentuk C yang
     * butuh `rincian_transaksi` per baris, makanya Bentuk C tetap pakai
     * `sumPerMetode()` di atas).
     *
     * Query manual (bukan lewat Eloquent SetoranHarian) makanya
     * `whereNull('deleted_at')` ditulis eksplisit -- soft-delete scope
     * Eloquent gak otomatis ikut kalau join lewat query builder biasa
     * (SetoranHarian pakai SoftDeletes, SetoranDetail tidak).
     *
     * @return array<int, array{transfer:int,qris:int,tunai:int,kpay:int}>
     */
    private function agregatMetodePerLoket(string $tanggalMulai, string $tanggalSelesai): array
    {
        $baris = SetoranDetail::query()
            ->join('setoran_harian', 'setoran_harian.id', '=', 'setoran_detail.setoran_harian_id')
            ->where('setoran_harian.status', 'submitted')
            ->whereNull('setoran_harian.deleted_at')
            ->whereBetween('setoran_harian.tanggal_transaksi', [$tanggalMulai, $tanggalSelesai])
            ->groupBy('setoran_harian.loket_id', 'setoran_detail.metode_pembayaran')
            ->selectRaw('setoran_harian.loket_id as loket_id, setoran_detail.metode_pembayaran as metode_pembayaran, SUM(setoran_detail.nominal) as total')
            ->get();

        $hasil = [];
        foreach ($baris as $b) {
            $hasil[(int) $b->loket_id][$b->metode_pembayaran] = (int) $b->total;
        }

        foreach ($hasil as $loketId => $metode) {
            // array_merge (bukan ??= satu-satu) SENGAJA -- biar urutan
            // key metode_setoran SELALU konsisten (transfer,qris,tunai,
            // kpay) apapun urutan baris balik dari GROUP BY (SQL gak
            // jamin urutan tanpa ORDER BY).
            $hasil[$loketId] = array_merge(
                ['transfer' => 0, 'qris' => 0, 'tunai' => 0, 'kpay' => 0],
                $metode,
            );
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, SetoranDetail>  $entries
     * @return array{transfer:int, qris:int, tunai:int, kpay:int}
     */
    private function sumPerMetode(Collection $entries): array
    {
        return [
            'transfer' => (int) $entries->where('metode_pembayaran', 'transfer')->sum('nominal'),
            'qris' => (int) $entries->where('metode_pembayaran', 'qris')->sum('nominal'),
            'tunai' => (int) $entries->where('metode_pembayaran', 'tunai')->sum('nominal'),
            'kpay' => (int) $entries->where('metode_pembayaran', 'kpay')->sum('nominal'),
        ];
    }

    private function statusKeuangan(?int $selisih): ?string
    {
        return $selisih !== null ? ($selisih === 0 ? 'lunas' : 'belum_lunas') : null;
    }

    /**
     * Baris total di bawah tabel -- SELALU dijumlah dari SELURUH item
     * periode (bukan dari 1 halaman hasil paging). Kalau ADA satu saja
     * hari/loket yang total_pendapatan_sopp-nya null (bridge gagal),
     * total_pendapatan_sopp gabungan JUGA null -- angka gabungan yang
     * sebagian ngasal lebih berbahaya daripada terang-terangan "tidak
     * lengkap", apalagi ini laporan keuangan.
     *
     * Private: LaporanExportController manggil generate() ulang per
     * bulan, jadi tiap hasil generate() sudah punya total_row sendiri
     * dan gak ada pemanggil luar yang butuh method ini.
     *
     * @param  list<array>  $items
     * @param  list<string>  $vendorRoot
     */
    private function totalRow(array $items, array $vendorRoot): array
    {
        $vendors = array_fill_keys($vendorRoot, 0);
        $metode = ['transfer' => 0, 'qris' => 0, 'tunai' => 0, 'kpay' => 0];
        $totalSetor = 0;
        $adaPendapatanNull = false;
        $adaVendorNull = false;
        $totalPendapatan = 0;

        foreach ($items as $item) {
            // Kalau 1 baris aja punya vendors:null (bridge gagal buat
            // baris itu), TOTAL vendors di sini juga jadi null -- gak
            // bisa jujur bilang "total vendor X = sekian" kalau salah
            // satu kontributornya gak diketahui. Sama persis pola
            // $adaPendapatanNull di bawah.
            if ($item['vendors'] === null) {
                $adaVendorNull = true;
            } else {
                foreach ($item['vendors'] as $v => $nilai) {
                    $vendors[$v] = ($vendors[$v] ?? 0) + $nilai;
                }
            }
            foreach ($item['metode_setoran'] as $m => $nilai) {
                $metode[$m] += $nilai;
            }
            $totalSetor += $item['total_setor'];

            if ($item['total_pendapatan_sopp'] === null) {
                $adaPendapatanNull = true;
            } else {
                $totalPendapatan += $item['total_pendapatan_sopp'];
            }
        }

        $totalPendapatanSopp = $adaPendapatanNull ? null : $totalPendapatan;
        $selisih = $totalPendapatanSopp !== null ? $totalPendapatanSopp - $totalSetor : null;

        return [
            'vendors' => $adaVendorNull ? null : $vendors,
            'metode_setoran' => $metode,
            'total_pendapatan_sopp' => $totalPendapatanSopp,
            'total_setor' => $totalSetor,
            'selisih' => $selisih,
            'status_keuangan' => $this->statusKeuangan($selisih),
        ];
    }
}
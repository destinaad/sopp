<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BridgeException;
use App\Http\Controllers\Api\Concerns\ThrottlesBridgeRefresh;
use App\Http\Controllers\Api\Concerns\UploadsBuktiSetoran;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SetoranHarian;
use App\Models\SetoranDetail;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

/**
 * Halaman Input Setoran -- Card B / Pelunasan Setoran Lama.
 * Card B TIDAK punya tabel/mekanisme terpisah -- entry yang dibuat di
 * sini masuk ke setoran_detail yang SAMA persis dengan Card A.
 *
 * REWRITE TOTAL (18 Agustus 2026) dari versi lama yang cuma terima 1
 * metode per transaksi. Keputusan final:
 *
 * 1. MULTI-METODE, MAX 1 BARIS PER METODE (maks 4 baris: tunai+qris+
 *    transfer+kpay). Bukan array bebas -- metode yang sama gak boleh
 *    diulang dalam 1 transaksi.
 * 2. CUMA 1x TRANSAKSI SELAMANYA per (USER, LOKET, TANGGAL) -- dicek
 *    langsung ke row milik user itu sendiri, karena tiap user paling
 *    banyak cuma punya 1 row per loket+tanggal (unique constraint DB).
 *    Ditolak kalau TELLER INI sudah pernah kena pelunasan untuk row
 *    itu sebelumnya (dicek dari ActivityLog, bukan dari setoran_detail
 *    -- tabel itu gak punya kolom penanda "ini dari pelunasan" sama
 *    sekali). Partial payment DIPERBOLEHKAN (total boleh < sisa_
 *    selisih); sisa yang belum lunas diselesaikan lewat Detail
 *    Setoran, BUKAN retry di sini.
 * 3. REWRITE (27 Agustus 2026) -- GAK ADA LAGI "numpang"/row kanonik.
 *    Entry pelunasan SELALU nempel ke row milik AKTOR SENDIRI, gak
 *    pernah ke row teller lain -- sesama loket maupun lintas loket gak
 *    dibedain lagi. Row teller LAIN di loket+tanggal yang sama CUMA
 *    DIBACA (buat syarat "loket ini pernah ada yang submit"), TIDAK
 *    PERNAH ditulis.
 *
 *    Kalau aktor belum punya row buat loket+tanggal yang dipilih, row
 *    itu di-auto-create atas nama dia sendiri (user_id = dia, status
 *    = 'submitted' langsung, ditandai dibuat_via_bantuan_pelunasan =
 *    true buat audit trail) -- lihat cariAtauBuatRowAktor(). Ini
 *    SENGAJA cuma kejadian di store() (POST), BUKAN di index() (GET/
 *    Muat Data) -- GET murni proyeksi/preview, gak boleh punya efek
 *    samping nulis row 'submitted' kosong yang bisa nyangkut permanen
 *    kalau user cuma preview terus gak jadi bayar (row submitted-
 *    kosong itu kena constraint unique(user,loket,tanggal) + gak bisa
 *    disubmit ulang lewat Card A -- bisa ngunci teller itu selamanya
 *    dari loket+tanggal itu cuma gara-gara dia klik "lihat data").
 *
 *    Syarat row baru boleh dibikin: loket TARGET itu udah pernah ada
 *    row submitted dari SIAPAPUN di tanggal itu (angka selisih tetap
 *    milik loket, bukan personal -- lihat SetoranHarian::selisih()).
 *    Bypass Card A ini boleh buat SIAPAPUN selama masih loket SENDIRI
 *    -- TANPA kapabilitas apapun. Alasan: Card A punya aturan "0
 *    transaksi gak bisa disubmit", jadi teller yang hari itu emang gak
 *    pegang transaksi tetap butuh jalan buat ikut nutup selisih
 *    loketnya sendiri.
 *
 *    OTORISASI buat loket LAIN: bikin row baru di loket manapun itu
 *    SECARA HARFIAH "submit atas nama loket manapun" -- definisi
 *    createForOtherLoket (bisa_input_setoran_semua_loket), SAMA
 *    seperti Card A. BUKAN updateForOtherLoket -- "edit" cuma relevan
 *    kalau nyentuh data ORANG LAIN yang sudah final, dan itu TIDAK
 *    PERNAH kejadian lagi di desain ini (row orang lain di loket+
 *    tanggal yang sama cuma dibaca, gak pernah ditulis).
 * 4. "Muat Data" (GET ?tanggal=...) FORCE REFRESH bridge, BUKAN baca
 *    cache TTL biasa -- beda dari Card A/Dashboard yang cache-nya
 *    cukup. Alasan: sekali tembak gak ada retry, jadi angka basi lebih
 *    berbahaya di sini.
 * 5. GAK ADA DRAFT sama sekali -- submit langsung final.
 * 6. TANGGAL: syarat eligible cuma submitted + ada sisa selisih --
 *    TIDAK ADA LAGI pengecualian "bukan hari ini". Kalau hari ini
 *    sudah submitted dan masih ada selisih, boleh langsung dilunasi
 *    hari itu juga.
 * 7. LOKET: pakai resolveLoketId() (pola sama kayak Card A) -- default
 *    loket user sendiri (TANPA gate apapun -- lihat poin 3), ?loket_id=
 *    buat verifikator yang mau lihat/lunasin loket lain (butuh
 *    bisa_input_setoran_semua_loket -- lihat poin 3, BUKAN
 *    bisa_edit_setoran_semua_loket). 403 kalau akun gak terhubung ke
 *    loket manapun.
 */
class PelunasanController extends Controller
{
    use ThrottlesBridgeRefresh;
    use UploadsBuktiSetoran;

    private const METODE_VALID = ['transfer', 'qris', 'tunai', 'kpay'];

    public function __construct(
        private readonly SnapshotService $snapshotService,
    ) {}

    /**
     * GET /api/teller/setoran/pelunasan
     * Tanpa ?tanggal= -> daftar tanggal eligible (cache doang).
     * Dengan ?tanggal= -> "Muat Data" (force-refresh 1 tanggal itu).
     * MURNI BACA -- gak ada row yang ditulis di jalur ini sama sekali
     * (lihat poin 3 di docblock atas).
     *
     * Loket ditentukan SEPENUHNYA oleh resolveLoketId() (default loket
     * sendiri, atau ?loket_id= buat capability holder) -- TIDAK ADA
     * LAGI percabangan "mode sendiri vs lintas loket". Satu jalur ini
     * dipakai rata buat siapapun, sama persis konsepnya kayak Card A.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $loketId = $this->resolveLoketId($request);

        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (isset($validated['tanggal'])) {
            return $this->muatDataUntukRow($user, $loketId, $validated['tanggal']);
        }

        return $this->daftarTanggalTersedia($loketId);
    }

    private function resolveLoketId(Request $request): int
    {
        $user = $request->user();

        if (! $request->filled('loket_id')) {
            if ($user->loket_id === null) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Akun ini tidak terhubung ke loket manapun.',
                    'errors' => (object) [],
                ], 403));
            }
            return $user->loket_id;
        }

        // Samain sama RiwayatSetoranController: loket_id ngasal (gak ada
        // di tabel lokets) ditolak 422 SEBELUM cek kapabilitas, biar FE
        // bisa bedain "loket beneran gak ada" (422) vs "loket ada tapi
        // gak punya hak akses" (403).
        $request->validate([
            'loket_id' => ['integer', 'exists:lokets,loket_id'],
        ]);

        // createForOtherLoket (bisa_input_setoran_semua_loket), BUKAN
        // updateForOtherLoket -- lihat poin 3 di docblock atas kelas.
        Gate::authorize('createForOtherLoket', SetoranHarian::class);
        return (int) $request->input('loket_id');
    }

    /**
     * Angka eligible SELALU dihitung dari agregat LOKET (semua row
     * submitted di loket+tanggal itu, siapapun tellernya) -- sama
     * sekali gak bergantung row siapa yang dipilih, karena selisih()
     * itu angka loket, bukan personal (lihat SetoranHarian::selisih()).
     * Dipakai bareng-bareng sama mode sendiri & lintas loket supaya
     * dua-duanya konsisten satu sumber logika.
     *
     * @return Collection<int, string> tanggal (Y-m-d), unik, terbaru dulu
     */
    private function hitungTanggalEligible(int $loketId): Collection
    {
        $rows = SetoranHarian::where('loket_id', $loketId)
            ->where('status', 'submitted')
            ->get(['id', 'loket_id', 'tanggal_transaksi', 'snapshot_sopp']);

        $totalSetorPerTanggal = SetoranDetail::join('setoran_harian', 'setoran_harian.id', '=', 'setoran_detail.setoran_harian_id')
            ->where('setoran_harian.loket_id', $loketId)
            ->where('setoran_harian.status', 'submitted')
            ->selectRaw('setoran_harian.tanggal_transaksi as tanggal, SUM(setoran_detail.nominal) as total')
            ->groupBy('setoran_harian.tanggal_transaksi')
            ->pluck('total', 'tanggal');

        return $rows
            ->filter(function (SetoranHarian $row) use ($totalSetorPerTanggal) {
                if ($row->snapshot_sopp === null) {
                    return true;
                }
                $totalSetor = (int) ($totalSetorPerTanggal[$row->tanggal_transaksi->toDateString()] ?? 0);
                $selisih = $row->totalPendapatanSopp() - $totalSetor;
                return $selisih > 0;
            })
            ->map(fn(SetoranHarian $row) => $row->tanggal_transaksi->toDateString())
            ->unique()
            ->sortByDesc(fn(string $tanggal) => $tanggal)
            ->values();
    }

    /**
     * Satu-satunya sumber daftar tanggal eligible -- dipakai rata buat
     * loket sendiri MAUPUN loket lain (?loket_id=, sudah di-authorize
     * di resolveLoketId()). nama_teller SENGAJA tidak disertakan --
     * siapa yang submit gak relevan buat keputusan pilih tanggal.
     * setoran_harian_id juga gak perlu dikirim lagi di sini, karena
     * store() sekarang nerima loket_id+tanggal langsung, bukan lewat
     * ID row rujukan (lihat cariAtauBuatRowAktor()).
     */
    private function daftarTanggalTersedia(int $loketId): JsonResponse
    {
        $tanggalTersedia = $this->hitungTanggalEligible($loketId)
            ->map(fn(string $tanggal) => ['tanggal' => $tanggal])
            ->values();

        return response()->json([
            'status' => 'ok',
            'data' => ['tanggal_tersedia' => $tanggalTersedia],
        ]);
    }

    /**
     * Force-refresh bridge + proyeksi sisa selisih. Satu-satunya jalur
     * pengambilan data Pelunasan, dipakai sama rata baik buat loket
     * sendiri maupun loket lain (loketId sudah di-resolve+authorize
     * di resolveLoketId() sebelum method ini dipanggil). MURNI BACA --
     * gak nulis row apapun (lihat poin 3 di docblock atas kelas).
     */
    private function muatDataUntukRow(User $user, int $loketId, string $tanggal): JsonResponse
    {
        // Cek eligibility DULU -- murni query DB, gak nembak bridge --
        // baru force-refresh bridge kalau tanggal ini beneran ada row
        // submitted-nya. Urutan ini penting: tanggal yang emang gak
        // eligible (belum ada yang submit / row-nya udah di-soft-
        // delete) harus balik 422 TANPA pernah nyoba ke bridge --
        // kalau kebalik (bridge duluan), tanggal yang gak eligible
        // bisa keliru balikin 502 pas bridge lagi down, padahal dari
        // awal harusnya 422 gak peduli bridge idup atau enggak.
        $adaRowSubmitted = SetoranHarian::where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->where('status', 'submitted')
            ->exists();

        if (! $adaRowSubmitted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tanggal ini tidak memenuhi syarat pelunasan.',
                'errors' => (object) [],
            ], 422);
        }

        $this->tolakJikaKenaRateLimit('refresh-pelunasan:' . $loketId . ':' . $tanggal);

        try {
            $hasilSnapshot = $this->snapshotService->getForLoketTanggal($loketId, $tanggal, true, $user);
        } catch (BridgeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data terbaru dari bridge, coba lagi.',
                'errors' => (object) [],
            ], 502);
        }

        // Prioritaskan row milik aktor sendiri (biar setoran_harian_id
        // di response ini konsisten kalau dia emang udah punya satu),
        // tapi kalau belum ada, pakai row submitted siapapun di loket+
        // tanggal ini -- angkanya identik, dan store() nanti yang bakal
        // bikin row aktor sendiri kalau memang belum ada.
        $rowUntukProyeksi = SetoranHarian::where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->where('status', 'submitted')
            ->where('user_id', $user->id)
            ->first()
            ?? SetoranHarian::where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->first();

        // Guard jaga-jaga doang -- secara teori gak akan null di titik
        // ini (udah dipastikan ada minimal 1 row submitted di atas),
        // kecuali race super sempit (row keburu di-soft-delete di
        // antara 2 query ini).
        if ($rowUntukProyeksi === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tanggal ini tidak memenuhi syarat pelunasan.',
                'errors' => (object) [],
            ], 422);
        }

        $sisaSelisih = $rowUntukProyeksi->selisih();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'setoran_harian_id' => $rowUntukProyeksi->id,
                'tanggal_transaksi' => $tanggal,
                'sisa_selisih' => $sisaSelisih,
                'sudah_lunas' => $sisaSelisih !== null && $sisaSelisih <= 0,
                'data_terkini' => $hasilSnapshot['data_terkini'],
                'fetched_at' => $hasilSnapshot['fetched_at'] ?? null,
            ],
        ]);
    }

    private function responFromPelunasanDitolak(PelunasanDitolakException $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'error_code' => $e->errorCode,
            'errors' => (object) [],
        ], $e->statusCode);
    }

    /**
     * Jantung dari keputusan "gak ada lagi numpang", SATU-SATUNYA
     * TEMPAT row Pelunasan pernah di-create() (lihat poin 3 di docblock
     * atas kelas -- kenapa ini gak boleh kejadian di GET). WAJIB
     * dipanggil dengan $rowSetanggal yang SUDAH di-lockForUpdate() oleh
     * pemanggil (store()) -- method ini sendiri gak ngunci apa-apa,
     * murni logic pencarian/pembuatan atas set yang udah dikunci.
     *
     * @param Collection<int, SetoranHarian> $rowSetanggal set loket+tanggal yang SUDAH di-lockForUpdate()
     * @throws PelunasanDitolakException
     */
    private function cariAtauBuatRowAktor(
        User $user,
        int $loketId,
        string $tanggal,
        Collection $rowSetanggal,
    ): SetoranHarian {
        $existing = $rowSetanggal->firstWhere('user_id', $user->id);
        if ($existing !== null) {
            return $existing;
        }

        // Row aktor belum ada -- syarat: loket+tanggal ini udah pernah
        // ada row SUBMITTED dari SIAPAPUN (row lain di sini CUMA
        // DIBACA buat syarat ini, TIDAK PERNAH ditulis).
        if (! $rowSetanggal->contains(fn(SetoranHarian $r) => $r->status === 'submitted')) {
            throw new PelunasanDitolakException('Tanggal ini tidak memenuhi syarat pelunasan.', 422);
        }

        // Salin snapshot_sopp dari row lain yang udah ada -- semua row
        // loket+tanggal ini seharusnya punya nilai identik (disiram
        // bareng oleh SnapshotService::getForLoketTanggal()), jadi row
        // baru ini aman ikutan dapet nilai yang sama tanpa perlu
        // manggil bridge lagi dari sini (store() emang gak nembak
        // bridge sama sekali).
        $snapshotSibling = $rowSetanggal
            ->first(fn(SetoranHarian $r) => $r->snapshot_sopp !== null)
            ?->snapshot_sopp;

        try {
            return SetoranHarian::create([
                'user_id' => $user->id,
                'loket_id' => $loketId,
                'tanggal_transaksi' => $tanggal,
                'status' => 'submitted',
                'submitted_at' => now(),
                'dibuat_via_bantuan_pelunasan' => true,
                'snapshot_sopp' => $snapshotSibling,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race: request lain (mis. double-klik submit, atau 2 tab)
            // barusan bikin row yang sama duluan -- unique constraint
            // (user_id, loket_id, tanggal_transaksi) kena. Ambil row
            // yang barusan kebentuk itu daripada gagal total.
            $race = SetoranHarian::where('user_id', $user->id)
                ->where('loket_id', $loketId)
                ->where('tanggal_transaksi', $tanggal)
                ->first();
            if ($race === null) {
                throw $e;
            }
            return $race;
        }
    }

    /**
     * POST /api/teller/setoran/pelunasan
     */
    public function store(Request $request): JsonResponse
    {
        // resolveLoketId() satu-satunya pintu otorisasi loket -- akun
        // tanpa loket -> 403, ?loket_id= ke loket lain -> exists check
        // 422 baru Gate::authorize('createForOtherLoket', ...) 403.
        // Dipanggil PALING AWAL, sebelum upload file apapun -- sama
        // alasannya kayak versi lama (hindari upload sia-sia buat
        // request yang udah pasti gagal otorisasi).
        $loketId = $this->resolveLoketId($request);
        $user = $request->user();

        $validated = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'metode_pembayaran' => ['required', 'array', 'min:1', 'max:4'],
            'metode_pembayaran.*.metode' => ['required', Rule::in(self::METODE_VALID)],
            'metode_pembayaran.*.nominal' => ['required', 'integer', 'min:1'],
            'metode_pembayaran.*.keterangan' => ['nullable', 'string'],
            'metode_pembayaran.*.bukti' => [
                'required_unless:metode_pembayaran.*.metode,kpay',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],
            'metode_pembayaran.*.is_konfirmasi' => ['sometimes', 'boolean'],
            'metode_pembayaran.*.nama_karyawan' => ['nullable', 'string', 'required_if:metode_pembayaran.*.metode,kpay', 'prohibited_unless:metode_pembayaran.*.metode,kpay'],
            'metode_pembayaran.*.identitas_karyawan' => ['nullable', 'string', 'required_if:metode_pembayaran.*.metode,kpay', 'prohibited_unless:metode_pembayaran.*.metode,kpay'],
        ]);

        $tanggal = $validated['tanggal'];

        $daftarMetode = collect($validated['metode_pembayaran'])->pluck('metode');
        if ($daftarMetode->count() !== $daftarMetode->unique()->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tiap metode pembayaran cuma boleh muncul sekali per transaksi pelunasan.',
                'errors' => (object) [],
            ], 422);
        }

        // Simpan semua file DI LUAR transaction database, biar gak ada
        // file nyangkut di disk kalau transaksinya rollback.
        $fotoPaths = [];
        try {
            foreach ($validated['metode_pembayaran'] as $i => $baris) {
                $fotoPaths[$i] = null;
                if ($request->hasFile("metode_pembayaran.$i.bukti")) {
                    $fotoPaths[$i] = $this->simpanFotoBukti(
                        $request->file("metode_pembayaran.$i.bukti"),
                        $tanggal,
                        $loketId,
                        $baris['metode'],
                    );
                }
            }
        } catch (\Throwable $e) {
            foreach ($fotoPaths as $path) {
                if ($path !== null) {
                    Storage::disk('local')->delete(self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $path);
                }
            }
            throw $e;
        }

        $suksesSimpanKeDb = false;
        try {
            $hasil = DB::transaction(function () use ($user, $validated, $request, $fotoPaths, $loketId, $tanggal) {
                $rowSetanggal = SetoranHarian::where('loket_id', $loketId)
                    ->where('tanggal_transaksi', $tanggal)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $setoranHarian = $this->cariAtauBuatRowAktor($user, $loketId, $tanggal, $rowSetanggal);

                if ($setoranHarian->status !== 'submitted') {
                    throw new PelunasanDitolakException('Setoran ini belum submitted, pelunasan cuma buat setoran yang sudah final.');
                }

                $sudahPernahDilunasi = ActivityLog::where('jenis_aksi', 'buat_pelunasan')
                    ->where('user_id', $user->id)
                    ->where('detail_perubahan->setoran_harian_id', $setoranHarian->id)
                    ->exists();
                if ($sudahPernahDilunasi) {
                    throw new PelunasanDitolakException('Kamu sudah pernah mengisi pelunasan untuk tanggal ini. Sisa selisih (jika ada) diselesaikan lewat Detail Setoran.');
                }

                $sisaSelisih = $setoranHarian->selisih();
                $totalNominal = collect($validated['metode_pembayaran'])->sum('nominal');

                if ($sisaSelisih === null) {
                    throw new PelunasanDitolakException(
                        'Sisa selisih belum diketahui karena data belum pernah dimuat (Muat Data dulu sebelum submit pelunasan).',
                        422,
                        'selisih_belum_dimuat',
                    );
                }

                if ($totalNominal > $sisaSelisih) {
                    throw new PelunasanDitolakException(
                        'Total nominal tidak boleh melebihi sisa selisih.',
                        422,
                        'nominal_melebihi_selisih',
                    );
                }

                $entriesBaru = [];

                foreach ($validated['metode_pembayaran'] as $i => $baris) {
                    $entry = $setoranHarian->entries()->create([
                        'metode_pembayaran' => $baris['metode'],
                        'nominal' => $baris['nominal'],
                        'keterangan' => $baris['keterangan'] ?? null,
                        'foto_bukti_path' => $fotoPaths[$i],
                        'is_konfirmasi' => $baris['is_konfirmasi'] ?? false,
                        'nama_karyawan' => $baris['metode'] === 'kpay' ? ($baris['nama_karyawan'] ?? null) : null,
                        'identitas_karyawan' => $baris['metode'] === 'kpay' ? ($baris['identitas_karyawan'] ?? null) : null,
                    ]);

                    ActivityLog::catat(
                        actor: $request->user(),
                        jenisAksi: 'buat_pelunasan',
                        objectType: 'setoran_detail',
                        objectId: $entry->id,
                        detailPerubahan: [
                            'setoran_harian_id' => $setoranHarian->id,
                            'metode_pembayaran' => $entry->metode_pembayaran,
                            'nominal' => $entry->nominal,
                        ],
                    );

                    $entriesBaru[] = $entry->toApiShape();
                }

                $setoranHarian->refresh();

                return [
                    'setoran_harian_id' => $setoranHarian->id,
                    'tanggal_transaksi' => $setoranHarian->tanggal_transaksi->toDateString(),
                    'selisih' => $setoranHarian->selisih(),
                    'status_keuangan' => match ($setoranHarian->isLunas()) {
                        true => 'lunas',
                        false => 'belum_lunas',
                        null => 'gagal_dimuat',
                    },
                    'total_setor' => SetoranHarian::totalSetorUntukLoketTanggal($loketId, $tanggal),
                    'entries' => $entriesBaru,
                ];
            }, 3);
            $suksesSimpanKeDb = true;
        } catch (PelunasanDitolakException $e) {
            return $this->responFromPelunasanDitolak($e);
        } finally {
            if (! $suksesSimpanKeDb) {
                foreach ($fotoPaths as $path) {
                    if ($path !== null) {
                        Storage::disk('local')->delete(self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $path);
                    }
                }
            }
        }

        return response()->json([
            'status' => 'ok',
            'data' => $hasil,
        ]);
    }
}

class PelunasanDitolakException extends \Exception
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        // Kode mesin buat FE bedain kasus tanpa parsing string pesan.
        // null = kasus lama yang belum dikasih kode spesifik.
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\UploadsBuktiSetoran;
use App\Models\ActivityLog;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\SelisihPerUserService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Detail Setoran (GET/PUT /api/v1/teller/setoran/{id}).
 *
 * Halaman ini cuma pernah beroperasi di row berstatus 'submitted' --
 * row 'draft' diedit lewat Card A (SetoranEntryController), bukan di
 * sini. DetailSetoranController.php versi LAMA SENGAJA TIDAK dipakai
 * sebagai rujukan sama sekali -- ditulis ulang dari nol niru pola
 * SetoranEntryController (Card A), PelunasanController, dan
 * RiwayatSetoranController yang sudah final. Rujukan lengkap: dokumen
 * "Kontrak Detail Setoran -- Working Doc (Revisi)"; nomor poin di
 * komentar bawah ini merujuk ke situ.
 *
 * CATATAN: `edit_setoran`/`hapus_setoran` Card A SUDAH diretrofit
 * (terpisah dari file ini) buat ikut nulis `setoran_harian_id` di
 * `detail_perubahan`, shape-nya PERSIS disamain ke field yang sama
 * kayak sebelum retrofit (cuma nambah 1 key baru, field lain gak
 * berubah) -- lihat petugas() di bawah soal kenapa itu gak lagi jadi
 * blocker buat 'input' (yang sekarang gak bergantung ke field itu
 * sama sekali).
 */
class DetailSetoranController extends Controller
{
    use UploadsBuktiSetoran;

    public function __construct(private readonly SelisihPerUserService $selisihPerUserService) {}

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        // Kolom user_id_sopp/is_superadmin ditambahin ke select --
        // dibutuhin SelisihPerUserService::untukSatuRow() buat cek
        // isTellerSopp(), gak dipakai buat apapun sebelumnya di sini.
        $row = SetoranHarian::with(['loket', 'entries', 'user:id,nama,user_id_sopp,is_superadmin'])->findOrFail($id);

        $this->authorizeView($row, $user);

        return response()->json([
            'status' => 'ok',
            'data' => $this->mapDetail($row, $user),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = SetoranHarian::findOrFail($id);

        $this->authorizeAkses($row, $user);

        // Urutan niru PERSIS SetoranEntryController::simpanEntriesBatch()
        // Card A: validasi payload DULUAN, baru debounce -- bukan
        // sebaliknya. Kalau kebalik, request yang gagal validasi (422)
        // ikut "membakar" jatah debounce 2 detiknya, jadi user yang
        // buru-buru benerin lalu retry malah bisa kena 429 padahal
        // belum pernah berhasil kirim apa-apa. Debounce di sini cegah
        // double-klik "Simpan" ngirim 2 PUT nyaris bersamaan ke row
        // yang sama; ini BUKAN pengganti optimistic lock di bawah
        // (yang nyegah 2 ORANG beda nabrak) -- dua-duanya independen,
        // jaga hal beda.
        $validated = $this->validateEntries($request, $row->id);

        $this->tolakJikaEditDobel('edit-detail-setoran:' . $user->id . ':' . $row->id);

        $fileBaruDitulis = [];

        DB::transaction(function () use ($validated, $request, $user, $row, &$fileBaruDitulis) {
            // Lock ULANG di sini (bukan cuma andelin hasil findOrFail() di
            // awal method) -- supaya 2 PUT bersamaan ke row yang sama gak
            // saling nyalip pas baca/tulis entries.
            $row = SetoranHarian::where('id', $row->id)->lockForUpdate()->firstOrFail();

            // FIX PENTING: cek optimistic lock DI SINI, SETELAH row
            // dikunci -- BUKAN sebelum masuk transaksi. Kalau dicek
            // sebelum lockForUpdate(), 2 request PUT barengan bisa
            // sama-sama baca entries_updated_at yang sama (masih basi),
            // sama-sama lolos cek (karena dicek sebelum ada lock apapun),
            // baru ngantre di lockForUpdate() -- begitu antreannya
            // jalan, request KEDUA tetep nimpa perubahan request
            // PERTAMA, padahal token lock yang dia kirim udah gak valid
            // lagi begitu request pertama commit. Optimistic lock
            // ADANYA justru buat nutup celah race kayak gini -- kalau
            // dicek pakai data dari SEBELUM lock didapat, gunanya
            // hilang setengah. Row yang dipakai buat cek harus row yang
            // paling baru dibaca SETELAH lock didapat.
            $this->cekOptimisticLock($row, $validated['entries_updated_at']);

            $entryLama = $row->entries()->get()->keyBy('id');
            $idDikirim = collect($validated['entries'])
                ->pluck('id')
                ->filter()
                ->map(fn($id) => (int) $id);

            try {
                // Baris lama yang gak ikut dikirim ulang -> HARD DELETE
                // (poin 2 -- beda dari draft Card A, di sini permanen).
                $entryLama->whereNotIn('id', $idDikirim)->each(
                    function (SetoranDetail $entry) use ($user, $row) {
                        if ($entry->foto_bukti_path) {
                            $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                            DB::afterCommit(function () use ($pathUntukDihapus) {
                                Storage::disk('local')->delete($pathUntukDihapus);
                            });
                        }

                        ActivityLog::catat(
                            actor: $user,
                            jenisAksi: 'hapus_setoran',
                            objectType: 'setoran_detail',
                            objectId: $entry->id,
                            detailPerubahan: [
                                'setoran_harian_id' => $row->id,
                                // Field list PERSIS Card A (jenis_aksi yang
                                // sama harus shape yang sama juga) -- jangan
                                // nambah kolom lain di sini walau Detail
                                // Setoran "punya lebih banyak" field yang
                                // relevan (nama_karyawan/identitas_karyawan).
                                'entry' => $entry->only(['metode_pembayaran', 'nominal', 'foto_bukti_path']),
                            ],
                        );

                        $entry->delete();
                    }
                );

                foreach ($validated['entries'] as $i => $item) {
                    $id = ! empty($item['id']) ? (int) $item['id'] : null;
                    $file = $request->file("entries.$i.foto_bukti");

                    if ($id !== null) {
                        $entry = $entryLama->get($id);
                        if ($entry === null) {
                            abort(response()->json([
                                'status' => 'error',
                                'message' => 'Salah satu entry tidak ditemukan atau bukan milik setoran ini.',
                                'errors' => (object) [],
                            ], 422));
                        }

                        $fotoPath = $entry->foto_bukti_path;
                        if ($item['metode_pembayaran'] === 'kpay') {
                            if ($entry->foto_bukti_path) {
                                $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                                DB::afterCommit(function () use ($pathUntukDihapus) {
                                    Storage::disk('local')->delete($pathUntukDihapus);
                                });
                            }
                            $fotoPath = null;
                        } elseif ($file !== null) {
                            if ($entry->foto_bukti_path) {
                                $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                                DB::afterCommit(function () use ($pathUntukDihapus) {
                                    Storage::disk('local')->delete($pathUntukDihapus);
                                });
                            }
                            $fotoPath = $this->simpanFotoBukti(
                                $file,
                                $row->tanggal_transaksi->toDateString(),
                                $row->loket_id,
                                $item['metode_pembayaran'],
                            );
                            $fileBaruDitulis[] = $fotoPath;
                        }

                        // Field list PERSIS Card A -- sama alasannya kayak
                        // hapus_setoran di atas.
                        $sebelum = $entry->only(['metode_pembayaran', 'nominal', 'keterangan', 'is_konfirmasi']);

                        $entry->update([
                            'metode_pembayaran' => $item['metode_pembayaran'],
                            'nominal' => $item['nominal'],
                            'keterangan' => $item['keterangan'] ?? null,
                            'is_konfirmasi' => $item['is_konfirmasi'] ?? false,
                            'foto_bukti_path' => $fotoPath,
                            'nama_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['nama_karyawan'] ?? null) : null,
                            'identitas_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['identitas_karyawan'] ?? null) : null,
                        ]);

                        ActivityLog::catat(
                            actor: $user,
                            jenisAksi: 'edit_setoran',
                            objectType: 'setoran_detail',
                            objectId: $entry->id,
                            detailPerubahan: [
                                'setoran_harian_id' => $row->id,
                                'sebelum' => $sebelum,
                                'sesudah' => $entry->only(['metode_pembayaran', 'nominal', 'keterangan', 'is_konfirmasi']),
                            ],
                        );
                    } else {
                        $fotoPath = $file !== null
                            ? $this->simpanFotoBukti(
                                $file,
                                $row->tanggal_transaksi->toDateString(),
                                $row->loket_id,
                                $item['metode_pembayaran'],
                            )
                            : null;
                        if ($fotoPath !== null) {
                            $fileBaruDitulis[] = $fotoPath;
                        }

                        $entryBaru = $row->entries()->create([
                            'metode_pembayaran' => $item['metode_pembayaran'],
                            'nominal' => $item['nominal'],
                            'keterangan' => $item['keterangan'] ?? null,
                            'is_konfirmasi' => $item['is_konfirmasi'] ?? false,
                            'foto_bukti_path' => $fotoPath,
                            'nama_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['nama_karyawan'] ?? null) : null,
                            'identitas_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['identitas_karyawan'] ?? null) : null,
                        ]);

                        ActivityLog::catat(
                            actor: $user,
                            jenisAksi: 'buat_setoran',
                            objectType: 'setoran_detail',
                            objectId: $entryBaru->id,
                            detailPerubahan: [
                                'setoran_harian_id' => $row->id,
                                'metode_pembayaran' => $entryBaru->metode_pembayaran,
                                'nominal' => $entryBaru->nominal,
                            ],
                        );
                    }
                }
            } catch (\Throwable $e) {
                foreach ($fileBaruDitulis as $path) {
                    Storage::disk('local')->delete(self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $path);
                }
                throw $e;
            }
        });

        $row->refresh();
        $row->load(['loket', 'entries', 'user:id,nama,user_id_sopp,is_superadmin']);

        return response()->json([
            'status' => 'ok',
            'data' => $this->mapDetail($row, $user),
        ]);
    }

    /**
     * Satu-satunya tempat cek kelayakan akses -- dipakai SAMA PERSIS
     * oleh show() dan update() (poin 1): kriteria non-pemilik buat
     * VIEW dan EDIT itu identik (bisa_edit_setoran_semua_loket DAN
     * status submitted), jadi satu method cukup.
     *
     * Status submitted WAJIB dicek DULUAN, berlaku ke SIAPAPUN
     * termasuk pemilik sendiri -> 404 kalau draft (konsisten Riwayat:
     * "gak boleh dilihat = gak pernah ada"; buat pemilik sendiri, ini
     * juga yang maksa dia balik ke Card A, bukan nembus draft-nya
     * lewat endpoint ini). Lolos status: pemilik SELALU boleh lanjut.
     * Non-pemilik masih harus lolos Gate::authorize (403 kalau gagal).
     * SENGAJA TIDAK ADA bypass is_superadmin (ability ini dikecualikan
     * dari Gate::before() global) dan TIDAK dicek bisa_akses_laporan
     * (scope beda, cuma buat foto satuan lewat BuktiSetoranController).
     */
    private function authorizeAkses(SetoranHarian $row, User $user): void
    {
        if ($row->status !== 'submitted') {
            // Berlaku ke SIAPAPUN termasuk pemilik sendiri -- Detail
            // Setoran cuma pernah beroperasi di row submitted, row draft
            // (biarpun punya sendiri) selalu diarahin ke Card A. 404
            // (bukan 403) konsisten sama pola "gak boleh dilihat = gak
            // pernah ada" yang dipakai di Riwayat.
            abort(404);
        }

        if ($row->user_id === $user->id) {
            return;
        }

        Gate::authorize('updateForOtherLoket', SetoranHarian::class);
    }

    /**
     * VIEW-only (dipanggil show() doang) -- ability `viewForOtherLoket`
     * SENGAJA beda dari `updateForOtherLoket` di atas: kontrak Laporan
     * §5 minta `bisa_akses_laporan` bisa VIEW read-only ke submitted
     * orang lain (buat navigasi dari Laporan), TANPA otomatis dapat
     * hak EDIT. Status submitted tetap wajib dicek DULUAN, sama
     * alasannya kayak authorizeAkses().
     */
    private function authorizeView(SetoranHarian $row, User $user): void
    {
        if ($row->status !== 'submitted') {
            abort(404);
        }

        if ($row->user_id === $user->id) {
            return;
        }

        Gate::authorize('viewForOtherLoket', SetoranHarian::class);
    }

    /**
     * Status submitted WAJIB dicek DULUAN, berlaku ke SIAPAPUN
     * termasuk pemilik sendiri -- konsisten sama authorizeAkses() di
     * atas (row draft gak pernah "editable" lewat endpoint ini, titik,
     * gak peduli siapa yang nanya). Lolos status: pemilik SELALU true;
     * non-pemilik identik sama syarat lolos authorizeAkses() (sengaja
     * gak dihitung ulang dari nol). Computed value ini murni buat
     * dikirim ke FE (FE dilarang replikasi logic sendiri). Pakai
     * Gate::allows() polos (bukan forUser()) biar konsisten sama
     * authorizeAkses() di file ini dan semua controller lain di
     * codebase ini (Card A, Pelunasan, Riwayat) -- semuanya cuma
     * pernah dipanggil lewat route HTTP auth:sanctum, jadi guard yang
     * di-resolve Gate udah pasti benar; forUser() gak nutup celah
     * nyata apapun di konteks pemakaian ini.
     */
    private function isEditable(SetoranHarian $row, User $user): bool
    {
        if ($row->status !== 'submitted') {
            return false;
        }

        if ($row->user_id === $user->id) {
            return true;
        }

        return Gate::allows('updateForOtherLoket', SetoranHarian::class);
    }

    /**
     * Cegah PUT dobel dari klik ganda/network lambat pada ROW YANG
     * SAMA. Window pendek (2 detik) -- niru persis
     * SetoranEntryController::tolakJikaEntryDobel().
     */
    private function tolakJikaEditDobel(string $key): void
    {
        if (! RateLimiter::attempt($key, 1, fn() => true, 2)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Tunggu sebentar sebelum menyimpan lagi.',
                'errors' => (object) [],
            ], 429));
        }
    }

    /**
     * Poin 4: mismatch -> 409 pesan generik. null di DB (row belum
     * pernah disentuh observer) -> skip cek, langsung diterima.
     *
     * PENTING: $row yang masuk ke sini WAJIB row yang sudah di-lock
     * (lockForUpdate(), dibaca ulang DI DALAM transaksi) -- lihat
     * komentar di update() soal kenapa cek sebelum lock itu race
     * condition yang nutup setengah gunanya optimistic lock.
     */
    private function cekOptimisticLock(SetoranHarian $row, ?string $dikirimMentah): void
    {
        if ($row->entries_updated_at === null) {
            return;
        }

        $dikirim = $dikirimMentah !== null ? Carbon::parse($dikirimMentah) : null;

        if ($dikirim === null || ! $dikirim->equalTo($row->entries_updated_at)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Data ini sudah diubah pihak lain, muat ulang dulu.',
                'errors' => (object) [],
            ], 409));
        }
    }

    /**
     * entries_updated_at pakai 'present' (bukan 'required') supaya
     * `null` yang di-echo balik FE (kasus DB-nya belum pernah disentuh
     * observer) tetap valid dikirim -- tapi field-nya WAJIB ada di
     * payload (poin 4: "FE wajib echo balik"). Kalau cuma 'nullable'
     * tanpa 'present', FE yang lupa nyertain field ini sama sekali
     * cuma ketauan pas kebetulan DB-nya gak null (lewat jalur 409
     * mismatch di atas) -- pas DB-nya null, permintaan yang field-nya
     * kelupaan itu bakal lolos diam-diam. 'present' nutup celah itu di
     * kedua kasus sekaligus.
     *
     * Duplikat id-dikirim-2x dan foto per-baris niru PERSIS pola
     * validateBatchEntries() Card A (poin 2 -- pola established, cuma
     * bedanya `entries` di sini SELALU required+min:1, gak pernah
     * mode draft-boleh-kosong).
     */
    private function validateEntries(Request $request, int $rowId): array
    {
        $entriesInput = $request->input('entries', []);

        $idsLama = collect($entriesInput)->pluck('id')->filter()->map(fn($id) => (int) $id);

        if ($idsLama->count() !== $idsLama->unique()->count()) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Satu entry (id) tidak boleh dikirim dua kali dalam satu request.',
                'errors' => (object) [],
            ], 422));
        }

        $metodeLamaById = SetoranDetail::whereIn('id', $idsLama)
            ->where('setoran_harian_id', $rowId)
            ->pluck('metode_pembayaran', 'id');

        $idTidakDikenal = $idsLama->diff($metodeLamaById->keys());
        if ($idTidakDikenal->isNotEmpty()) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Salah satu entry tidak ditemukan atau bukan milik setoran ini.',
                'errors' => (object) [],
            ], 422));
        }

        $rules = [
            'entries_updated_at' => ['present', 'nullable', 'date'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.id' => ['nullable', 'integer'],
            'entries.*.metode_pembayaran' => ['required', Rule::in(['transfer', 'qris', 'tunai', 'kpay'])],
            'entries.*.nominal' => ['required', 'integer', 'min:1'],
            'entries.*.keterangan' => ['nullable', 'string'],
            'entries.*.is_konfirmasi' => ['sometimes', 'boolean'],
            'entries.*.nama_karyawan' => [
                'nullable', 'string',
                'required_if:entries.*.metode_pembayaran,kpay',
                'prohibited_unless:entries.*.metode_pembayaran,kpay',
            ],
            'entries.*.identitas_karyawan' => [
                'nullable', 'string',
                'required_if:entries.*.metode_pembayaran,kpay',
                'prohibited_unless:entries.*.metode_pembayaran,kpay',
            ],
        ];

        foreach ($entriesInput as $i => $item) {
            $metodeBaru = $item['metode_pembayaran'] ?? null;
            $id = ! empty($item['id']) ? (int) $item['id'] : null;
            $metodeLama = $id !== null ? ($metodeLamaById[$id] ?? null) : null;

            if ($metodeBaru === 'kpay') {
                $rules["entries.$i.foto_bukti"] = ['prohibited'];
            } elseif ($id === null) {
                $rules["entries.$i.foto_bukti"] = ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            } elseif ($metodeLama === 'kpay') {
                $rules["entries.$i.foto_bukti"] = ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            } else {
                $rules["entries.$i.foto_bukti"] = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            }
        }

        return $request->validate($rules);
    }

    private function mapDetail(SetoranHarian $row, User $user): array
    {
        $entries = $row->entries;

        // Selisih + pendapatan PERSONAL (punya row ini doang) -- BEDA
        // dari selisih()/totalPendapatanSopp() loket-wide yang lama
        // (sudah dihapus dari response ini, keputusan final). Live-fetch
        // ke bridge, null-safe kalau gagal atau usernya gak punya
        // identitas legacy (superadmin) -- lihat SelisihPerUserService.
        // Ketiganya (pendapatan, total_pendapatan, selisih_sendiri)
        // SEKARANG gagal BARENGAN dari 1 sumber yang sama (fetch ini),
        // bukan 2 sumber independen (snapshot_sopp vs bridge) kayak
        // sebelumnya.
        $dataPersonal = $this->selisihPerUserService->untukSatuRow($row);

        // KEPUTUSAN FINAL: status_keuangan turunan dari selisih_sendiri
        // (personal). 'gagal_dimuat' berarti "fetch bridge buat teller
        // ini gagal / gak punya identitas legacy".
        $statusKeuangan = $dataPersonal['selisih_sendiri'] === null
            ? 'gagal_dimuat'
            : ($dataPersonal['selisih_sendiri'] === 0 ? 'lunas' : 'belum_lunas');

        return [
            'id' => $row->id,
            'tanggal_transaksi' => $row->tanggal_transaksi->toDateString(),
            'nama_loket' => $row->loket?->nama_loket ?? '-',
            // Niru pola fallback string '-' punya RiwayatSetoranController
            // (bukan raw null) buat kasus loket-nya udah kehapus.
            'nama_teller' => $row->user?->nama ?? '-',
            'status_workflow' => $row->status,
            'is_editable' => $this->isEditable($row, $user),
            'entries_updated_at' => $row->entries_updated_at?->toIso8601String(),
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'entries' => $entries->map(fn(SetoranDetail $e) => $e->toApiShape())->values()->all(),
            'ringkasan_setoran' => $this->ringkasanSetoran($row, $entries),
            // KEPUTUSAN FINAL: pendapatan & total_pendapatan SEKARANG
            // PERSONAL (breakdown per-vendor punya teller ini doang,
            // dari bridge live) -- BUKAN lagi loket-wide dari
            // snapshot_sopp. Nama field & key ('pendapatan',
            // 'vendor_id', 'vendor_name', 'kategori', 'total',
            // 'total_pendapatan') SENGAJA TIDAK diganti sama sekali,
            // biar FE yang udah pakai nama-nama ini gak perlu berubah --
            // yang berubah cuma ISI datanya, dari loket-wide jadi
            // personal. Angka gabungan loket (combined) DIHAPUS total
            // dari halaman ini (keputusan final) -- kalau dibutuhkan,
            // itu domain Laporan, bukan Detail Setoran.
            'pendapatan' => $dataPersonal['pendapatan'],
            'total_pendapatan' => $dataPersonal['total_pendapatan'],
            'status_keuangan' => $statusKeuangan,
            // 'selisih' loket-wide SENGAJA DIHAPUS dari response ini
            // (keputusan final) -- diganti 'selisih_sendiri' di bawah.
            // Card A/Dashboard/Pelunasan TIDAK terpengaruh, mereka
            // masih pakai SetoranHarian::selisih() apa adanya.
            'selisih_sendiri' => $dataPersonal['selisih_sendiri'],
            'petugas' => $this->petugas($row),
            'dibuat_via_bantuan_pelunasan' => $row->dibuat_via_bantuan_pelunasan,
        ];
    }

    /**
     * Poin 11 (FINAL): tetap PERSONAL, bukan loket-wide. Pakai
     * $row->totalSetor() yang udah ada (bukan
     * totalSetorUntukLoketTanggal() -- itu cuma buat selisih()), dan
     * breakdown per-metode dari entries row ini doang.
     */
    private function ringkasanSetoran(SetoranHarian $row, $entries): array
    {
        return [
            'total_setor' => $row->totalSetor(),
            'nominal_transfer' => (int) $entries->where('metode_pembayaran', 'transfer')->sum('nominal'),
            'nominal_qris' => (int) $entries->where('metode_pembayaran', 'qris')->sum('nominal'),
            'nominal_tunai' => (int) $entries->where('metode_pembayaran', 'tunai')->sum('nominal'),
            'nominal_kpay' => (int) $entries->where('metode_pembayaran', 'kpay')->sum('nominal'),
        ];
    }

    /**
     * Poin 7: isi CUMA orang yang nyentuh ROW INI SENDIRI, dari 2
     * sumber ActivityLog yang beda level:
     *
     *  - 'input': dari `submit_setoran` (Card A, target_type=
     *    setoran_harian, target_id LANGSUNG = row ini). Selalu
     *    maksimal 1 baris -- momen tunggal "row ini pertama kali
     *    final". SENGAJA BUKAN dari `buat_setoran` (yang fire per
     *    entry, bisa berkali-kali selagi masih bolak-balik ngedraft
     *    sebelum submit, dan bakal bikin 1 orang numpuk di 'input'
     *    dengan jam yang nyaris sama -- gak match "submitter awal"
     *    yang dimaksud poin 7).
     *
     *  - 'edit'/'hapus'/'pelunasan': dari `edit_setoran`/`hapus_setoran`/
     *    `buat_pelunasan`, di-scan lewat detail_perubahan->setoran_harian_id
     *    (bukan join lewat target_id -- hapus_setoran gak bisa
     *    di-join balik setelah hard delete, poin 3). `buat_setoran`
     *    yang kejadian SETELAH submitted_at (entry ketinggalan
     *    ditambahin belakangan lewat Detail Setoran) ikut masuk
     *    kelompok ini sebagai 'edit' -- nambah baris baru ke row yang
     *    udah final ya termasuk mengoreksi row itu. SEBALIKNYA,
     *    `buat_setoran`/`edit_setoran`/`hapus_setoran` APAPUN yang
     *    kejadian SEBELUM ATAU PERSIS PAS submitted_at (histori
     *    revisi draft di Card A) DISARING KELUAR SEMUANYA -- itu
     *    histori "lagi ngisi form", bukan koreksi pasca-final.
     *    `buat_pelunasan` TIDAK PERNAH kena filter ini (struktural
     *    cuma bisa terjadi setelah submitted).
     */
    private function petugas(SetoranHarian $row): array
    {
        $petugas = collect();

        $logSubmit = ActivityLog::where('target_type', 'setoran_harian')
            ->where('target_id', $row->id)
            ->where('jenis_aksi', 'submit_setoran')
            ->with('user')
            ->first();

        if ($logSubmit !== null) {
            $petugas->push([
                'nama' => $logSubmit->user?->nama ?? '-',
                'aksi' => 'input',
                'waktu' => $logSubmit->created_at,
            ]);
        }

        $submittedAt = $row->submitted_at;

        $logEntry = ActivityLog::where('target_type', 'setoran_detail')
            ->where('detail_perubahan->setoran_harian_id', $row->id)
            ->whereIn('jenis_aksi', ['buat_setoran', 'edit_setoran', 'hapus_setoran', 'buat_pelunasan'])
            ->with('user')
            ->get();

        foreach ($logEntry as $log) {
            $praSubmit = $log->jenis_aksi !== 'buat_pelunasan'
                && ($submittedAt === null || $log->created_at->lte($submittedAt));

            if ($praSubmit) {
                continue;
            }

            $aksi = match ($log->jenis_aksi) {
                'buat_setoran', 'edit_setoran' => 'edit',
                'hapus_setoran' => 'hapus',
                'buat_pelunasan' => 'pelunasan',
            };

            $petugas->push([
                'nama' => $log->user?->nama ?? '-',
                'aksi' => $aksi,
                'waktu' => $log->created_at,
            ]);
        }

        return $petugas->sortBy('waktu')->values()->map(fn($p) => [
            'nama' => $p['nama'],
            'aksi' => $p['aksi'],
            'waktu' => $p['waktu']->toIso8601String(),
        ])->all();
    }
}
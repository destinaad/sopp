<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BridgeException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\User;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Api\Concerns\UploadsBuktiSetoran;
use App\Http\Controllers\Api\Concerns\ThrottlesBridgeRefresh;

/**
 * Halaman Input Setoran -- Card A (§7.2).
 *
 * MODEL BATCH: FE nyimpen semua baris di state React selagi teller
 * masih ngisi (tombol "+ Tambah"/"Hapus" TIDAK manggil API sama
 * sekali). Baru pas "Simpan Draft"/"Submit" dipencet, SATU request
 * dikirim isinya array `entries[]` -- baris lama (bawa `id`, dari
 * hasil GET index() sebelumnya) di-upsert, baris yang hilang dari
 * array (ada di DB tapi gak dikirim ulang) dihapus. Endpoint single-
 * entry lama (POST hari-ini buat satu baris) DIHAPUS, digantikan
 * simpanDraft()/submit() yang sama-sama nerima payload batch ini.
 *
 * SOAL KAPABILITAS -- dua scope yang beda, JANGAN disamain:
 * - CRUD di controller INI (simpanDraft/submit) itu selalu nyentuh
 *   row MILIK SENDIRI doang (user_id = actor), di loket manapun.
 *   Gate-nya cuma SATU: boleh nunjuk loket_id ORANG LAIN kalau punya
 *   bisa_input_setoran_semua_loket (dicek sekali di resolveLoketId()).
 *   Begitu itu lolos, create/update/delete berkali-kali di dalam row
 *   itu TIDAK butuh cek ulang -- bukan celah, karena scope-nya dibatasi
 *   constraint (user_id+loket_id+tanggal), bukan otorisasi aplikasi.
 * 
 * TANGGAL: Card A punya dropdown tanggal (default hari ini), dikirim
 * lewat parameter `tanggal` (query buat index(), field form buat
 * simpanDraft()/submit()). Gak ada batas mundur, tapi tanggal masa
 * depan ditolak (resolveTanggal()). Kalau parameter ini gak dikirim
 * sama sekali, default ke hari ini.
 *
 * LOCK SETELAH SUBMIT: begitu status row = 'submitted', row itu READ
 * ONLY lewat controller ini -- simpanDraft() maupun submit() lagi ke
 * tanggal yang sama ditolak eksplisit (409). Perubahan lanjutan (nambah
 * entry ketinggalan, edit, hapus) itu domain halaman Detail Setoran
 * (chunk terpisah), BUKAN Card A. Card A cuma pernah nge-set status jadi
 * 'submitted' SEKALI per row -- gak pernah flip balik ke 'draft'.
 */
class SetoranEntryController extends Controller
{
    use ThrottlesBridgeRefresh;
    use UploadsBuktiSetoran;

    public function __construct(
        private readonly SnapshotService $snapshotService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('refresh');
        $loketId = $this->resolveLoketId($request);
        $tanggal = $this->resolveTanggal($request);

        if ($forceRefresh) {
            $this->tolakJikaKenaRateLimit('refresh-setoran:' . $request->user()->id . ':' . $loketId . ':' . $tanggal);
        }

        return $this->buildResponse($request, $tanggal, $forceRefresh, $loketId);
    }

    /**
     * POST /api/v1/teller/setoran/draft
     */
    public function simpanDraft(Request $request): JsonResponse
    {
        return $this->simpanEntriesBatch($request, 'draft');
    }

    /**
     * POST /api/v1/teller/setoran/submit -- TIDAK ADA validasi
     * selisih harus nol. submitted_at diisi HANYA kalau masih null
     * (§14, momen submit PERTAMA KALI, tidak direset submit ulang).
     * Begitu status 'submitted', row itu READ ONLY lewat controller ini
     * -- simpanDraft()/submit() lagi ke tanggal yang sama ditolak (409).
     * Perubahan lanjutan lewat Detail Setoran, bukan di sini.
     */
    public function submit(Request $request): JsonResponse
    {
        return $this->simpanEntriesBatch($request, 'submitted');
    }

    private function simpanEntriesBatch(Request $request, string $statusTarget): JsonResponse
    {
        $user = $request->user();
        $loketId = $this->resolveLoketId($request);
        $tanggalTarget = $this->resolveTanggal($request);

        $validated = $this->validateBatchEntries($request, $statusTarget, $user, $loketId, $tanggalTarget);

        $this->tolakJikaEntryDobel('simpan-setoran:' . $user->id . ':' . $loketId . ':' . $tanggalTarget);


        $hasil = DB::transaction(function () use ($validated, $request, $user, $loketId, $tanggalTarget, $statusTarget) {
            // FIX: sebelumnya SELALU coba INSERT dulu (create()), padahal
            // di alur normal row buat tanggal ini biasanya SUDAH ada
            // (teller pencet "Simpan Draft" berkali-kali sepanjang hari
            // isi form yang sama) -- artinya request kedua dst SELALU
            // nembak QueryException (unique violation) dan baru fallback
            // ke locked-select. Exception PHP itu gak murah, dan ini
            // jadi jalur "normal", bukan cuma pas race asli. Dibalik:
            // coba locked-select DULU (cepat, gak exception buat kasus
            // yang paling sering kejadian), INSERT cuma dicoba kalau
            // beneran belum ada row-nya -- catch di bawah TETAP
            // dipertahankan buat nutup celah race asli (dua request
            // barengan sama-sama gak nemu row terus sama-sama coba
            // insert, salah satu bakal kalah kena unique violation).
            $setoranHarian = SetoranHarian::where('user_id', $user->id)
                ->where('loket_id', $loketId)
                ->where('tanggal_transaksi', $tanggalTarget)
                ->lockForUpdate()
                ->first();

            if ($setoranHarian === null) {
                try {
                    $setoranHarian = SetoranHarian::create([
                        'user_id' => $user->id,
                        'loket_id' => $loketId,
                        'tanggal_transaksi' => $tanggalTarget,
                        'status' => 'draft',
                    ]);
                } catch (QueryException $e) {
                    if (! $this->adalahRaceConditionUniqueViolation($e)) {
                        throw $e;
                    }
                    $pesan = $e->getMessage();
                    $adalahUniqueViolation = str_contains($pesan, 'Duplicate entry')        // MySQL
                        || str_contains($pesan, 'UNIQUE constraint failed');                // SQLite

                    // FIX: SQLSTATE 23000 dipakai bareng buat unique violation MAUPUN
                    // foreign key violation -- cek kode doang gak cukup. Tambahan negative-
                    // check ini jaga-jaga kalau suatu saat pesan errornya nyebut FK
                    // walau entah kenapa juga ngandung salah satu frasa di atas (harusnya
                    // gak pernah kejadian bareng, tapi lebih aman eksplisit daripada
                    // ngandelin "gak akan pernah").
                    $adalahForeignKeyViolation = str_contains($pesan, 'foreign key constraint')
                        || str_contains($pesan, 'FOREIGN KEY constraint failed');

                    if ($e->getCode() != 23000 || ! $adalahUniqueViolation || $adalahForeignKeyViolation) {
                        throw $e;
                    }

                    $setoranHarian = SetoranHarian::where('user_id', $user->id)
                        ->where('loket_id', $loketId)
                        ->where('tanggal_transaksi', $tanggalTarget)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            if ($setoranHarian->status === 'submitted') {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Setoran tanggal ini sudah disubmit dan tidak bisa diubah lagi lewat halaman ini.',
                    'errors' => (object) [],
                ], 409));
            }

            // FIX: file foto BARU (baris baru maupun ganti foto di baris
            // lama) sebelumnya ditulis LANGSUNG ke disk di tengah loop --
            // synchronous, gak ditunda kayak pola hapus-foto-lama yang
            // udah pakai DB::afterCommit(). Akibatnya: kalau entry LAIN
            // di batch yang SAMA gagal belakangan (mis. id gak valid ->
            // abort 422), DB rollback bersih (row gak kesimpen), TAPI
            // file yang udah keburu ditulis buat entry sebelumnya tetap
            // nangkring di disk selamanya, orphan, gak ke-link row
            // manapun. $fileBaruDitulis nampung path tiap file baru yang
            // ditulis SELAMA proses batch ini; kalau closure ini throw
            // apapun (abort 422/409, atau error lain), semua file yang
            // sempat ditulis di percobaan ini dihapus balik sebelum
            // exception-nya dilempar lagi.
            $fileBaruDitulis = [];

            try {
                $entryLama = $setoranHarian->entries()->get()->keyBy('id');
                $idDikirim = collect($validated['entries'])
                    ->pluck('id')
                    ->filter()
                    ->map(fn($id) => (int) $id);

                // Baris lama yang gak ikut kekirim lagi -> dianggap dihapus
                // teller dari form.
                $entryLama->whereNotIn('id', $idDikirim)->each(function (SetoranDetail $entry) use ($user, $setoranHarian) {
                    // UBAH: hapus fisik ditunda sampai transaksi COMMIT beneran --
                    // kalau transaksi ini di-rollback belakangan (entry lain di
                    // request yang sama gagal), callback ini otomatis DIBATALIN
                    // sama Laravel, foto lama TETAP UTUH, konsisten sama row-nya
                    // yang juga balik ke database via rollback.
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
                            // FIX (dipicu audit Detail Setoran §3): wajib ada,
                            // BUKAN opsional -- hard delete bikin target_id gak
                            // bisa di-join balik ke setoran_harian_id manapun
                            // begitu row-nya lenyap dari DB. Tanpa field ini,
                            // petugas[] Detail Setoran gak akan pernah nemu
                            // histori hapus yang kejadian lewat Card A.
                            'setoran_harian_id' => $setoranHarian->id,
                            'entry' => $entry->only(['metode_pembayaran', 'nominal', 'foto_bukti_path']),
                        ],
                    );
                    $entry->delete();
                });

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
                            // UBAH: sama pola-nya, tunda ke afterCommit()
                            if ($entry->foto_bukti_path) {
                                $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                                DB::afterCommit(function () use ($pathUntukDihapus) {
                                    Storage::disk('local')->delete($pathUntukDihapus);
                                });
                            }
                            $fotoPath = null;
                        } elseif ($file !== null) {
                            // UBAH: sama pola-nya juga
                            if ($entry->foto_bukti_path) {
                                $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                                DB::afterCommit(function () use ($pathUntukDihapus) {
                                    Storage::disk('local')->delete($pathUntukDihapus);
                                });
                            }
                            $fotoPath = $this->simpanFotoBukti($file, $tanggalTarget, $loketId, $item['metode_pembayaran']);
                            $fileBaruDitulis[] = $fotoPath;
                        }

                        $sebelum = $entry->only(['metode_pembayaran', 'nominal', 'keterangan', 'is_konfirmasi']);
                        $entry->update([
                            'metode_pembayaran' => $item['metode_pembayaran'],
                            'nominal' => $item['nominal'],
                            'keterangan' => $item['keterangan'] ?? null,
                            'is_konfirmasi' => $item['is_konfirmasi'] ?? false,
                            'foto_bukti_path' => $fotoPath,
                            // FIX: dipaksa null kalau bukan kpay, jangan
                            // percaya begitu saja isi $item -- validasi
                            // 'prohibited_unless' di atas sudah nolak
                            // payload semacam ini, tapi baris ini jaga-jaga
                            // (defense in depth) supaya nama_karyawan lama
                            // gak pernah nyangkut di entry yang baru pindah
                            // metode dari kpay ke metode lain.
                            'nama_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['nama_karyawan'] ?? null) : null,
                            'identitas_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['identitas_karyawan'] ?? null) : null,
                        ]);

                        ActivityLog::catat(
                            actor: $user,
                            jenisAksi: 'edit_setoran',
                            objectType: 'setoran_detail',
                            objectId: $entry->id,
                            detailPerubahan: [
                                // FIX (audit Detail Setoran §3): dulu gak ada,
                                // sekarang wajib -- shape seragam ke-4 jenis
                                // aksi (§3), biar petugas[] Detail Setoran bisa
                                // nemu log ini tanpa join lewat target_id.
                                'setoran_harian_id' => $setoranHarian->id,
                                'sebelum' => $sebelum,
                                'sesudah' => $entry->only(['metode_pembayaran', 'nominal', 'keterangan', 'is_konfirmasi']),
                            ],
                        );
                    } else {
                        // Baris baru.
                        $fotoPath = $file !== null
                            ? $this->simpanFotoBukti($file, $tanggalTarget, $loketId, $item['metode_pembayaran'])
                            : null;
                        if ($fotoPath !== null) {
                            $fileBaruDitulis[] = $fotoPath;
                        }

                        $entryBaru = $setoranHarian->entries()->create([
                            'metode_pembayaran' => $item['metode_pembayaran'],
                            'nominal' => $item['nominal'],
                            'keterangan' => $item['keterangan'] ?? null,
                            'is_konfirmasi' => $item['is_konfirmasi'] ?? false,
                            'foto_bukti_path' => $fotoPath,
                            // FIX: sama, dipaksa null kalau bukan kpay.
                            'nama_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['nama_karyawan'] ?? null) : null,
                            'identitas_karyawan' => $item['metode_pembayaran'] === 'kpay' ? ($item['identitas_karyawan'] ?? null) : null,
                        ]);

                        ActivityLog::catat(
                            actor: $user,
                            jenisAksi: 'buat_setoran',
                            objectType: 'setoran_detail',
                            objectId: $entryBaru->id,
                            detailPerubahan: [
                                'setoran_harian_id' => $setoranHarian->id,
                                'metode_pembayaran' => $entryBaru->metode_pembayaran,
                                'nominal' => $entryBaru->nominal,
                            ],
                        );
                    }
                }

                if ($statusTarget === 'submitted') {
                    if ($setoranHarian->entries()->count() === 0) {
                        abort(response()->json([
                            'status' => 'error',
                            'message' => 'Tidak bisa submit tanpa entry sama sekali.',
                            'errors' => (object) [],
                        ], 422));
                    }

                    $setoranHarian->status = 'submitted';
                    if ($setoranHarian->submitted_at === null) {
                        $setoranHarian->submitted_at = now();
                    }
                    $setoranHarian->save();

                    // FIX: tambah sebelum/sesudah status draft->submitted --
                    // sebelumnya perubahan status ini sama sekali tidak
                    // tercatat, padahal ini momen bisnis yang layak diaudit.
                    // Field tanggal_transaksi/loket_id yang lama TETAP
                    // dipertahankan (dipakai di tempat lain, lihat komentar
                    // di ActivityLog::catat) -- cuma nambah, bukan ganti.
                    ActivityLog::catat(
                        actor: $user,
                        jenisAksi: 'submit_setoran',
                        objectType: 'setoran_harian',
                        objectId: $setoranHarian->id,
                        detailPerubahan: [
                            'tanggal_transaksi' => $setoranHarian->tanggal_transaksi->toDateString(),
                            'loket_id' => $setoranHarian->loket_id,
                            'sebelum' => ['status' => 'draft'],
                            'sesudah' => ['status' => 'submitted'],
                        ],
                    );
                }
                // statusTarget === 'draft': row udah kepastian statusnya
                // 'draft' (baik baru dibikin lewat create() di atas, atau
                // lolos guard "bukan submitted" barusan) -- gak perlu
                // di-update apa-apa lagi di sini.

            } catch (\Throwable $e) {
                // Bersihin semua file baru yang keburu ditulis SELAMA
                // percobaan ini sebelum exception-nya dilempar lagi --
                // DB::transaction() bakal rollback DB-nya, tapi filesystem
                // gak ikut ke-rollback otomatis, jadi harus dibersihin manual.
                foreach ($fileBaruDitulis as $path) {
                    Storage::disk('local')->delete(self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $path);
                }
                throw $e;
            }

            return $setoranHarian;
        });

        return $this->buildResponse($request, $tanggalTarget, false, $loketId);
    }

    /**
     * Resolusi loket_id yang mau dikerjakan. Default: loket milik user
     * login sendiri. Kalau ada parameter loket_id eksplisit di request
     * (dari dropdown pilih cabang di FE), WAJIB dicek kapabilitas dulu
     * -- ditolak 403 kalau user gak punya bisa_input_setoran_semua_loket,
     * SEKALIPUN nilainya kebetulan sama dengan loket sendiri (keputusan
     * sengaja: kirim parameter ini tanpa hak sama sekali sudah dianggap
     * penyalahgunaan, bukan cuma soal nilai akhirnya beda atau tidak).
     */
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

        // Samain sama RiwayatSetoranController/PelunasanController:
        // loket_id ngasal (gak ada di tabel lokets) ditolak 422 SEBELUM
        // cek kapabilitas.
        $request->validate([
            'loket_id' => ['integer', 'exists:lokets,loket_id'],
        ]);
        Gate::authorize('createForOtherLoket', SetoranHarian::class);

        return (int) $request->input('loket_id');
    }

    /**
     * Resolve tanggal target dari parameter `tanggal` (query buat
     * index(), field form buat simpanDraft()/submit()). Gak ada batas
     * mundur (teller boleh balik ke tanggal berapapun), tapi tanggal
     * masa depan ditolak (422). Default ke hari ini kalau parameter ini
     * gak dikirim sama sekali.
     */
    private function resolveTanggal(Request $request): string
    {
        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        return $validated['tanggal'] ?? Carbon::today()->toDateString();
    }

    /**
     * Cegah "Simpan Draft"/"Submit" ke-klik dobel (network lambat,
     * klik dua kali) ngirim 2 request batch identik -- baris baru
     * (tanpa id) bakal ke-create dua kali kalau ini gak dijaga. Window
     * pendek (2 detik), cukup nangkep klik ganda tanpa ganggu teller
     * yang genuinely mau save lagi habis nambah entry baru.
     */
    private function tolakJikaEntryDobel(string $key): void
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
     * Validasi array `entries[]`. foto_bukti WAJIB buat baris BARU
     * (gak ada `id`) kecuali metode kpay (kpay dilarang punya foto);
     * OPSIONAL buat baris LAMA (ada `id`) -- gak disentuh artinya foto
     * lama dipertahankan (§A4, disepakati sama pola Detail Setoran).
     */
    private function validateBatchEntries(
        Request $request,
        string $statusTarget,
        User $user,
        int $loketId,
        string $tanggalTarget,
    ): array {
        $entriesInput = $request->input('entries', []);

        if ($entriesInput === '[]' || $entriesInput === '') {
            $entriesInput = [];
            $request->merge(['entries' => []]);
}

        $idsLama = collect($entriesInput)->pluck('id')->filter()->map(fn($id) => (int) $id);

        // FIX: sebelumnya id yang sama boleh muncul 2x+ di satu payload
        // -- gak nge-crash, tapi row itu ke-update berkali-kali dalam
        // satu request (item terakhir yang menang, yang lain silently
        // ketimpa) dan activity_logs kebanjiran entry "edit_setoran"
        // duplikat buat satu perubahan yang sama. Ditolak eksplisit di
        // sini, sebelum masuk transaksi -- bukan pakai rule bawaan
        // Laravel `distinct` karena itu ikut nganggep beberapa `id`
        // null (baris BARU, wajar ada banyak per request) sebagai
        // "duplikat satu sama lain", padahal cuma id yang KE-ISI yang
        // perlu dijamin unik.
        if ($idsLama->count() !== $idsLama->unique()->count()) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Satu entry (id) tidak boleh dikirim dua kali dalam satu request.',
                'errors' => (object) [],
            ], 422));
        }

        $metodeLamaById = SetoranDetail::whereIn('id', $idsLama)
            ->whereHas('setoranHarian', fn($q) => $q->where('user_id', $user->id)
                ->where('loket_id', $loketId)
                ->where('tanggal_transaksi', $tanggalTarget))
            ->pluck('metode_pembayaran', 'id');

        // Draft BOLEH kosong (entries: []) -- itu cara teller ngosongin
        // form-nya total. Submit TETAP wajib minimal 1 baris ("kalau 0
        // transaksi yaudah gausah disubmit").
        // PENTING: draft pakai 'present', BUKAN 'required' -- rule
        // 'required' bawaan Laravel udah nolak array kosong duluan,
        // independen dari 'min:1'. 'present' cuma minta key-nya ada di
        // request, boleh isinya kosong.
        $entriesRule = $statusTarget === 'submitted'
            ? ['required', 'array', 'min:1']
            : ['present', 'array'];

        $rules = [
            'entries' => $entriesRule,
            'entries.*.id' => ['nullable', 'integer'],
            'entries.*.metode_pembayaran' => ['required', Rule::in(['transfer', 'qris', 'tunai', 'kpay'])],
            'entries.*.nominal' => ['required', 'integer', 'min:1'],
            'entries.*.keterangan' => ['nullable', 'string'],
            'entries.*.is_konfirmasi' => ['sometimes', 'boolean'],
            'entries.*.nama_karyawan' => ['nullable', 'string', 'required_if:entries.*.metode_pembayaran,kpay', 'prohibited_unless:entries.*.metode_pembayaran,kpay'],
            'entries.*.identitas_karyawan' => ['nullable', 'string', 'required_if:entries.*.metode_pembayaran,kpay', 'prohibited_unless:entries.*.metode_pembayaran,kpay'],
        ];

        foreach ($entriesInput as $i => $item) {
            $metodeBaru = $item['metode_pembayaran'] ?? null;
            $id = ! empty($item['id']) ? (int) $item['id'] : null;
            $metodeLama = $id !== null ? ($metodeLamaById[$id] ?? null) : null;

            if ($metodeBaru === 'kpay') {
                // Kpay ga boleh punya foto -- berlaku sama rata, baris
                // baru maupun lama, apapun metode sebelumnya.
                $rules["entries.$i.foto_bukti"] = ['prohibited'];
            } elseif ($id === null) {
                // Baris baru, bukan kpay -> wajib ada foto.
                $rules["entries.$i.foto_bukti"] = ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            } elseif ($metodeLama === 'kpay') {
                // Baris lama PINDAH dari kpay ke metode lain -- gak ada
                // foto lama buat dipertahankan (kpay emang ga pernah
                // punya foto), jadi wajib upload baru.
                $rules["entries.$i.foto_bukti"] = ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            } else {
                // Baris lama, tetap bukan kpay -> opsional, gak disentuh
                // artinya foto lama dipertahankan.
                $rules["entries.$i.foto_bukti"] = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'];
            }
        }

        return $request->validate($rules);
    }

    private function buildResponse(Request $request, string $tanggal, bool $forceRefresh, int $loketId): JsonResponse
    {
        $user = $request->user();

        $setoranHarian = SetoranHarian::where('user_id', $user->id)
            ->where('loket_id', $loketId)
            ->where('tanggal_transaksi', $tanggal)
            ->first();
        $isPersisted = $setoranHarian !== null;

        try {
            $snapshot = $this->snapshotService->getForLoketTanggal($loketId, $tanggal, $forceRefresh, $user);
        } catch (BridgeException $e) {
            $snapshot = null;
        }

        $pendapatanSoppStatus = $snapshot === null ? 'gagal_dimuat' : null;
        $totalPendapatanSopp = $snapshot['grand_total'] ?? null;
        $dataPendapatanSopp = $snapshot !== null
            ? array_map(fn($row) => [
                'vendor_id' => $row['vendor_id'],
                'vendor_name' => $row['vendor_name'],
                'kategori' => $row['kategori'],
                'total' => $row['total'],
            ], $snapshot['data'])
            : null;

        $entriesSaya = $isPersisted ? $setoranHarian->entries()->get() : collect();
        $totalSetorSaya = (int) $entriesSaya->sum('nominal');

        $totalSetorLoket = SetoranHarian::totalSetorUntukLoketTanggal($loketId, $tanggal);

        $selisih = $totalPendapatanSopp !== null ? ($totalPendapatanSopp - $totalSetorLoket) : null;
        $statusKeuangan = $totalPendapatanSopp !== null ? ($selisih === 0 ? 'lunas' : 'belum_lunas') : null;

        return response()->json([
            'status' => 'ok',
            'data' => [
                'setoran_harian_id' => $isPersisted ? $setoranHarian->id : null,
                'tanggal_transaksi' => $tanggal,
                'nama_loket' => Loket::find($loketId)?->nama_loket,
                'nama_teller' => $user->nama,
                'status_workflow' => $isPersisted ? $setoranHarian->status : 'belum_input',
                'status_keuangan' => $statusKeuangan,
                'is_persisted' => $isPersisted,
                'is_locked' => $isPersisted && $setoranHarian->status === 'submitted',
                'fetched_at' => $snapshot['fetched_at'] ?? null,
                'pendapatan_sopp_status' => $pendapatanSoppStatus,
                'data_pendapatan_sopp' => $dataPendapatanSopp,
                'total_pendapatan' => $totalPendapatanSopp,
                'entries' => $entriesSaya->map(fn(SetoranDetail $e) => $e->toApiShape())->all(),
                'total_setor' => $totalSetorSaya,
                'total_setor_loket' => $totalSetorLoket,
                'selisih' => $selisih,
            ],
        ]);
    }

    /**
     * GET /api/teller/kalender-setoran?loket_id=&bulan=YYYY-MM
     * Status per tanggal buat date-picker Card A. Cek LOKAL doang
     * (setoran_harian) -- TIDAK ada panggilan bridge/SnapshotService.
     * ada_selisih SENGAJA gak disertain di sini, itu domain Pelunasan.
     */
    public function kalenderSetoran(Request $request): JsonResponse
    {
        $loketId = $this->resolveLoketId($request);
        $user = $request->user();

        $validated = $request->validate([
            'bulan' => ['required', 'date_format:Y-m'],
        ]);

        // BUGFIX 7 September 2026: createFromFormat('Y-m', ...) TANPA
        // paksa day=1 di string-nya itu rollover bug -- hari-di-bulan
        // ikut ke-fill dari HARI INI (bukan selalu 1), dan kalau hari
        // ini tanggal 31 sementara bulan target cuma 28/30 hari, hasilnya
        // diam-diam geser ke BULAN LAIN (Februari 31 -> Maret 3, lalu
        // startOfMonth() jadi 1 Maret, BUKAN 1 Februari). Paksa day=1 di
        // STRING sebelum parse, bukan normalisasi setelahnya.
        $awalBulan = Carbon::createFromFormat('Y-m-d', $validated['bulan'] . '-01')->startOfDay();
        $akhirBulan = $awalBulan->copy()->endOfMonth();
        $hariIni = Carbon::today();

        // 1 query buat seluruh bulan, bukan loop per tanggal.
        $rows = SetoranHarian::where('user_id', $user->id)
            ->where('loket_id', $loketId)
            ->whereBetween('tanggal_transaksi', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
            ->get(['tanggal_transaksi', 'status'])
            ->keyBy(fn($row) => $row->tanggal_transaksi->toDateString());

        $kalender = [];
        for ($tanggal = $awalBulan->copy(); $tanggal->lte($akhirBulan); $tanggal->addDay()) {
            $tanggalStr = $tanggal->toDateString();
            $row = $rows->get($tanggalStr);
            $status = $row?->status ?? 'belum_input';

            $kalender[] = [
                'tanggal' => $tanggalStr,
                'status' => $status,
                // BUGFIX 20 Agustus 2026: lt() -> lte(). Hari ini harus tetap
                // bisa_diinput:true, konsisten sama resolveTanggal() (before_or_equal:today).
                'bisa_diinput' => $tanggal->lte($hariIni) && $status !== 'submitted',
            ];
        }

        return response()->json([
            'status' => 'ok',
            'data' => ['bulan' => $validated['bulan'], 'kalender' => $kalender],
        ]);
    }

    /**
     * SQLSTATE 23000 dipakai bareng buat unique violation MAUPUN foreign
     * key violation -- cek kode doang gak cukup buat mastiin ini beneran
     * race-condition "row udah kebikin duluan", bukan pelanggaran FK yang
     * kebetulan numpang kode error yang sama.
     */
    private function adalahRaceConditionUniqueViolation(QueryException $e): bool
    {
        $pesan = $e->getMessage();

        $adalahUniqueViolation = str_contains($pesan, 'Duplicate entry')
            || str_contains($pesan, 'UNIQUE constraint failed');

        $adalahForeignKeyViolation = str_contains($pesan, 'foreign key constraint')
            || str_contains($pesan, 'FOREIGN KEY constraint failed');

        return $e->getCode() == 23000 && $adalahUniqueViolation && ! $adalahForeignKeyViolation;
    }
}
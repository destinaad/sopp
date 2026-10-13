<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SsoTokenException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Loket;
use App\Services\SsoTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SsoController extends Controller
{
    public function __construct(
        private readonly SsoTokenService $ssoTokenService,
    ) {}

    /**
     * POST /api/sso/verify
     *
     * Dipanggil React setelah dia baca token dari query string dan
     * mengambil CSRF cookie (GET /sanctum/csrf-cookie) lebih dulu.
     *
     * Response mengikuti "Format Response Standar" kontrak API §2 --
     * SELALU dibungkus {status, data} untuk sukses, {status, message,
     * errors} untuk gagal -- BUKAN bentuk {status, user} yang dipakai
     * di revisi sebelumnya, supaya konsisten dengan seluruh endpoint
     * lain di sistem ini, tidak ada pengecualian bentuk khusus untuk
     * endpoint ini.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $payload = $this->ssoTokenService->verifyAndDecode($validated['token']);
        } catch (SsoTokenException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                // (object) [] WAJIB, bukan [] polos -- json_encode([])
                // menghasilkan "[]" (array JSON), bukan "{}" (object
                // JSON) seperti yang didokumentasikan di kontrak §2.2.
                'errors'  => (object) [],
            ], 401);
        }

        $user = $this->provisionOrRefreshUser($payload);

        Auth::guard('web')->login($user);

        // Wajib di-regenerate setelah login supaya session ID lama
        // (yang mungkin sudah "dilihat" browser sebelum login, kalau
        // ada) tidak bisa dipakai buat session fixation attack.
        $request->session()->regenerate();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'user' => array_merge(
                    [
                        'nama' => $user->nama,
                        'loket_id' => $user->loket_id,
                        'nama_loket' => Loket::find($user->loket_id)?->nama_loket,
                    ],
                    $user->kapabilitasUntukApi(), // §3.4 -- OR-pattern terpusat, jangan tulis manual di sini lagi
                ),
            ],
        ]);
    }

    /**
     * Cari row user_id_sopp ini. Kalau belum ada, bikin baru
     * (auto-provisioning, level teller polos, tanpa kapabilitas
     * apapun). Kalau sudah ada, refresh nama/loket_id dari payload
     * TERBARU, tapi JANGAN PERNAH sentuh kolom kapabilitas -- itu
     * murni keputusan manual superadmin (lihat diskusi "solusi
     * kasar" edge case mutasi loket).
     *
     * DB::transaction() membungkus provisioning + activity log jadi
     * satu unit -- kalau log gagal tercatat karena alasan apapun,
     * pembuatan/update user-nya juga ikut di-rollback, supaya tidak
     * ada state "user berhasil dibuat tapi tidak ada jejaknya sama
     * sekali di activity_logs".
     */
    private function provisionOrRefreshUser(array $payload): User
    {
        return DB::transaction(function () use ($payload) {
            $this->ensureLoketExists($payload['loket_id']);

            try {
                $user = User::firstOrCreate(
                    ['user_id_sopp' => $payload['user_id']],
                    [
                        'loket_id' => $payload['loket_id'],
                        'nama'     => $payload['nama'],
                    ]
                );
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000') {
        throw $e; // bukan duplicate-key, jangan ditelen -- lempar lagi biar keliatan errornya
    }
                // Race condition langka: request lain dengan
                // user_id_sopp yang sama barusan berhasil insert
                // duluan, tepat di antara firstOrCreate() cek-dan-
                // insertnya sendiri. Unique constraint di kolom
                // user_id_sopp yang menggagalkan insert kita di
                // sini -- itu tandanya row-nya sudah ada, tinggal
                // diambil.
                $user = User::where('user_id_sopp', $payload['user_id'])->firstOrFail();
            }

            if ($user->wasRecentlyCreated) {
                ActivityLog::catat(
                    actor: $user,
                    jenisAksi: 'auto_provisioning',
                    objectType: 'users',
                    objectId: $user->id,
                    detailPerubahan: [
                        'user_id_sopp' => $payload['user_id'],
                        'loket_id' => $payload['loket_id'],
                    ],
                );

                return $user;
            }

            $loketLama = $user->loket_id;
            $loketBaru = $payload['loket_id'];

            $user->nama     = $payload['nama'];
            $user->loket_id = $loketBaru;
            $user->save();

            if ($loketLama !== $loketBaru) {
                ActivityLog::catat(
                    actor: $user,
                    jenisAksi: 'mutasi_loket_terdeteksi',
                    objectType: 'users',
                    objectId: $user->id,
                    detailPerubahan: [
                        'loket_lama' => $loketLama,
                        'loket_baru' => $loketBaru,
                    ],
                );
            }

            return $user;
        });
    }

    /**
     * Mastiin baris `lokets` buat loket_id ini ADA, walau namanya belum
     * pernah disinkron dari bridge (sopp:sync-loket masih manual --
     * lihat komentar di SyncLoket.php). Tanpa ini, loket yang baru
     * dibuka di sistem lama bakal ke-block validasi exists:lokets di
     * Riwayat/Card A/Pelunasan sampai ada yang inget jalanin sync
     * manual, walau transaksinya sendiri sudah sah.
     *
     * Placeholder doang -- gak nembak bridge sama sekali di sini,
     * konsisten sama prinsip "semua fetch ke bridge dipicu aksi
     * eksplisit" (lihat SyncLoket.php). Nama aslinya nyusul kapan aja
     * lewat sync manual biasa.
     *
     * loket_id itu primary key NON-INCREMENTING (lihat Loket.php),
     * jadi rawan race yang sama persis kayak user_id_sopp di atas: 2
     * teller loket baru yang sama login bersamaan bisa dua-duanya lolos
     * cek "belum ada" lalu tabrakan insert. Pola tambalannya sama.
     */
    private function ensureLoketExists(int $loketId): void
    {
        try {
            Loket::firstOrCreate(
                ['loket_id' => $loketId],
                ['nama_loket' => "Loket #{$loketId} (belum disinkron)"],
            );
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Baris-nya sudah dibuat request lain barusan -- aman
            // diabaikan, gak perlu di-refetch karena caller cuma butuh
            // KEPASTIAN barisnya ada, bukan objeknya.
        }
    }
}
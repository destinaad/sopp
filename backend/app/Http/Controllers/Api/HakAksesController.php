<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HakAksesController extends Controller
{
    private const PER_PAGE = 20; // FINAL, keputusan BE

    /**
     * Satu-satunya 5 kolom kapabilitas yang boleh diubah lewat endpoint ini.
     * `role` dan `aktif` SENGAJA tidak ada di sini -- dibatalkan total oleh
     * FE (bukan ditunda), role cukup di-derive dari kombinasi flag ini,
     * tidak pernah jadi kolom tersimpan terpisah.
     */
    private const PERMISSION_KEYS = [
        'bisa_akses_laporan',
        'bisa_atur_hak_akses',
        'bisa_lihat_log',
        'bisa_input_setoran_semua_loket',
        'bisa_edit_setoran_semua_loket',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAksesHalaman($request);

        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        // Superadmin sengaja gak dimasukin ke list ini sama sekali -- bukan
        // cuma di-skip pas render toggle. Ini nyelesain soal toggle 3 dari 5
        // kapabilitas yang percuma buat superadmin (di-OR terus jadi true di
        // kapabilitasUntukApi()): kalau mereka gak pernah muncul, gak ada
        // toggle yang bisa keliatan "berhasil" padahal gak ngefek apa-apa.
        $users = User::where('is_superadmin', false)->orderBy('nama')->paginate(self::PER_PAGE);

        // Satu query buat semua nama loket di halaman ini, bukan satu query
        // per user (sebelumnya Loket::find() dipanggil di dalam map(), N+1).
        $loketIds = $users->getCollection()->pluck('loket_id')->filter()->unique()->all();
        $namaLoketById = Loket::whereIn('loket_id', $loketIds)->pluck('nama_loket', 'loket_id');

        return response()->json([
            'status' => 'ok',
            'data' => [
                'items' => $users->getCollection()->map(fn (User $u) => $this->mapUser($u, $namaLoketById))->all(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => self::PER_PAGE,
                    'total_items' => $users->total(),
                    'total_pages' => $users->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Payload: {perubahan: [{id, nama, tipe, dari, ke}, ...]}
     *
     * Semantik WAJIB (disepakati dengan FE):
     * - all-or-nothing: satu item gagal (duplikat id+tipe, tipe tidak
     *   dikenal, self-edit, target superadmin, user tidak ketemu, atau
     *   `dari` stale) -> SELURUH batch ditolak, tidak ada yang ke-apply.
     * - `dari` divalidasi terhadap nilai kolom saat ini di DB SEBELUM
     *   apply `ke`, dan divalidasi ULANG setelah row dikunci
     *   (lockForUpdate(), pola sama persis dengan cekOptimisticLock() di
     *   DetailSetoranController) supaya dua PUT nyaris-bersamaan gak
     *   bisa dua-duanya lolos baca nilai lama sebelum salah satu nulis.
     * - Superadmin gak pernah jadi target: sudah gak muncul di GET, dan
     *   di sini diblokir eksplisit lagi kalau ada id yang manipulated.
     * - Response sukses SELALU {status:"ok", data:null} -- FE update
     *   state lokal sendiri, tidak butuh data balik (disepakati:
     *   validasi dari + all-or-nothing menjamin sukses = state FE
     *   sudah pasti sama dengan DB).
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeAksesHalaman($request);

        $validated = $request->validate([
            'perubahan' => ['required', 'array', 'min:1'],
            'perubahan.*.id' => ['required', 'integer'],
            'perubahan.*.nama' => ['nullable', 'string'], // hanya buat keperluan pesan/log, bukan dipakai cari record
            'perubahan.*.tipe' => ['required', 'string'],
            'perubahan.*.dari' => ['required', 'boolean'],
            'perubahan.*.ke' => ['required', 'boolean'],
        ]);

        $actor = $request->user();
        $perubahan = $validated['perubahan'];

        // ---- PASS 1: validasi murah, belum nyentuh row yang mau dikunci ----

        $idTipePair = collect($perubahan)->map(fn ($item) => $item['id'].'|'.$item['tipe']);
        if ($idTipePair->count() !== $idTipePair->unique()->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Satu field (id + tipe) tidak boleh dikirim dua kali dalam satu request.',
                'errors' => (object) [],
            ], 422);
        }

        foreach ($perubahan as $i => $item) {
            if (! in_array($item['tipe'], self::PERMISSION_KEYS, true)) {
                throw ValidationException::withMessages([
                    "perubahan.$i.tipe" => "Field '{$item['tipe']}' tidak dikenali atau tidak bisa diubah lewat halaman ini.",
                ]);
            }

            // Self-block WAJIB, berlaku untuk SIAPA PUN termasuk superadmin,
            // dicek independen dari authorize() di atas. Satu item nyasar ke
            // diri sendiri = seluruh batch ditolak, bukan cuma item ini yang
            // di-skip.
            if ((int) $item['id'] === $actor->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak bisa mengubah hak akses milik sendiri.',
                    'errors' => (object) [],
                ], 403);
            }
        }

        $targetIds = collect($perubahan)->pluck('id')->unique()->all();
        $targets = User::whereIn('id', $targetIds)->get()->keyBy('id');

        foreach ($perubahan as $i => $item) {
            if (! $targets->has($item['id'])) {
                throw ValidationException::withMessages([
                    "perubahan.$i.id" => "User dengan id {$item['id']} tidak ditemukan.",
                ]);
            }

            // Blokir eksplisit, bukan cuma andelin GET yang udah exclude
            // superadmin dari list -- kalau ada request yang manipulated
            // atau id-nya stale, target superadmin tetap ditolak di sini.
            if ($targets->get($item['id'])->is_superadmin) {
                throw ValidationException::withMessages([
                    "perubahan.$i.id" => 'Kapabilitas superadmin tidak bisa diubah lewat halaman ini.',
                ]);
            }
        }

        // ---- PASS 2: lock tiap row DI DALAM transaction, baca ulang nilai
        // SETELAH lock diambil, baru dibandingin sama `dari` -- niru PERSIS
        // pola lockForUpdate() di DetailSetoranController (cekOptimisticLock),
        // supaya dua PUT nyaris-bersamaan gak bisa dua-duanya lolos baca
        // nilai lama sebelum salah satu sempat nulis.

        $konflik = null;

        DB::transaction(function () use ($perubahan, $targetIds, $actor, &$konflik) {
            $targetsTerkunci = User::whereIn('id', $targetIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($perubahan as $item) {
                $target = $targetsTerkunci->get($item['id']);
                $nilaiSaatIni = (bool) $target->{$item['tipe']};
                $dariClient = (bool) $item['dari'];

                if ($nilaiSaatIni !== $dariClient) {
                    $konflik = $target->nama;

                    return; // batalin transaction, gak ada yang ke-apply
                }
            }

            foreach ($perubahan as $item) {
                $target = $targetsTerkunci->get($item['id']);
                $sebelum = [$item['tipe'] => (bool) $target->{$item['tipe']}];

                // Assignment LANGSUNG per-kolom, BUKAN $target->update() --
                // kolom kapabilitas sengaja di luar $fillable (cegah mass
                // assignment privilege escalation), jadi update() massal
                // bakal DIAM-DIAM gagal nyentuh kolom ini. Assignment
                // langsung ($target->x = y) selalu lolos $fillable, ini
                // SATU-SATUNYA tempat yang boleh ngelakuin itu justru
                // karena udah lewat authorize()+self-block+validasi dari di atas.
                $target->{$item['tipe']} = $item['ke'];
                $target->save();

                ActivityLog::catat(
                    actor: $actor,
                    jenisAksi: 'ubah_kapabilitas',
                    objectType: 'users',
                    objectId: $target->id,
                    detailPerubahan: ['sebelum' => $sebelum, 'sesudah' => [$item['tipe'] => (bool) $item['ke']]],
                );
            }
        });

        if ($konflik !== null) {
            return response()->json([
                'status' => 'error',
                'message' => "Data untuk user '{$konflik}' sudah berubah sejak halaman dimuat, silakan muat ulang.",
                'errors' => (object) [],
            ], 409);
        }

        return response()->json(['status' => 'ok', 'data' => null]);
    }

    private function mapUser(User $u, Collection $namaLoketById): array
    {
        return array_merge(
            [
                'id' => $u->id,
                'nama' => $u->nama,
                'username' => $u->user_id_sopp ?? $u->username,
                'loket' => $u->loket_id ? $namaLoketById->get($u->loket_id) : null,
            ],
            $u->kapabilitasUntukApi(),
        );
    }

    private function authorizeAksesHalaman(Request $request): void
    {
        $user = $request->user();
        if (! $user->is_superadmin && ! $user->bisa_atur_hak_akses) {
            abort(403, 'Tidak memiliki akses ke halaman ini.');
        }
    }
}
<?php
// =====================================================================
// FILE: app/Http/Controllers/Api/LogAktivitasController.php
//
// Acuan lengkap: dokumen "spek-log-aktivitas-admin.md". Nomor bagian di
// komentar bawah ini merujuk ke situ.
// =====================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class LogAktivitasController extends Controller
{
    /**
     * Daftar jenis_aksi resmi (Bagian 2.5) -- dipakai buat validasi query
     * param `aksi`. APPROVE/REVISION dari dokumen FE lama SENGAJA tidak
     * ada di sini (tidak ada dasarnya di kode / sudah digabung ke
     * edit_setoran).
     */
    private const JENIS_AKSI_VALID = [
        'login_manual', 'login_gagal', 'logout_manual',
        'buat_setoran', 'edit_setoran', 'hapus_setoran', 'submit_setoran',
        'buat_pelunasan', 'setoran_dihapus',
        'riwayat_lintas_loket_dilihat', 'riwayat_lintas_loket_diekspor',
        'auto_provisioning', 'mutasi_loket_terdeteksi', 'vendor_baru_terdeteksi',
        // FIX (audit Log Aktivitas): 5 nilai ini SUDAH ditulis beneran ke
        // activity_logs (HakAksesController::update,
        // PengaturanController::updatePassword, LaporanController::index,
        // LaporanExportController::unduh) tapi kelewat gak dimasukkan ke
        // whitelist filter ini -- akibatnya admin kena 422 kalau coba
        // filter ?aksi=ubah_kapabilitas dkk, padahal datanya ada.
        'ubah_kapabilitas', 'atur_password',
        'laporan_dilihat', 'laporan_diekspor', 'laporan_diunduh',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Bagian 0 -- guard pakai kapabilitas terpusat, JANGAN ditulis
        // manual is_superadmin || bisa_lihat_log di sini (lihat komentar
        // di ActivityLog::catat soal kenapa).
        if (! $user->kapabilitasUntukApi()['bisa_lihat_log']) {
            abort(403, 'Tidak memiliki akses ke log aktivitas.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'aksi' => ['sometimes', 'string', Rule::in(self::JENIS_AKSI_VALID)],
            'tanggal_mulai' => ['sometimes', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
        ]);

        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 10;

        $query = ActivityLog::query()
            ->with(['user', 'targetLoket'])
            ->orderByDesc('created_at');

        if (isset($validated['aksi'])) {
            $query->where('jenis_aksi', $validated['aksi']);
        }

        if (isset($validated['tanggal_mulai'])) {
            $query->whereDate('created_at', '>=', $validated['tanggal_mulai']);
        }

        if (isset($validated['tanggal_akhir'])) {
            $query->whereDate('created_at', '<=', $validated['tanggal_akhir']);
        }

        if (isset($validated['search']) && trim($validated['search']) !== '') {
            $this->terapkanSearch($query, trim($validated['search']));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $items = $paginator->getCollection();

        [$setoranHarianMap, $setoranDetailMap, $userMap, $loketMutasiMap] = $this->siapkanPetaBatch($items);

        $itemsResponse = $items->map(function (ActivityLog $log) use (
            $setoranHarianMap,
            $setoranDetailMap,
            $userMap,
            $loketMutasiMap
        ) {
            return [
                'id' => $log->id,
                'waktu' => $log->created_at->toIso8601String(),
                'user' => $log->user?->nama ?? '-',
                'role' => $log->actor_role,
                'loket' => $log->targetLoket?->nama_loket,
                'aksi' => $log->jenis_aksi,
                'target' => $this->resolveTarget($log, $setoranHarianMap, $setoranDetailMap, $userMap),
                'perubahan' => $this->resolvePerubahan($log, $loketMutasiMap),
            ];
        });

        return response()->json([
            'status' => 'ok',
            'data' => [
                'items' => $itemsResponse->values(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total_items' => $paginator->total(),
                    'total_pages' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Bagian 2.8 -- search ke DATA MENTAH, bukan ke string tampilan
     * `target`. Numerik -> match ID setoran_harian (langsung ATAU lewat
     * setoran_harian_id di detail_perubahan buat baris setoran_detail).
     * Teks -> match nama pelaku, nama loket, atau metode_pembayaran.
     */
    private function terapkanSearch($query, string $search): void
    {
        $isNumeric = ctype_digit($search);

        $query->where(function ($outer) use ($search, $isNumeric) {
            $outer->whereHas('user', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%");
            })->orWhereHas('targetLoket', function ($q) use ($search) {
                $q->where('nama_loket', 'like', "%{$search}%");
            });

            if ($isNumeric) {
                $id = (int) $search;

                $outer->orWhere(function ($q) use ($id) {
                    $q->where('target_type', 'setoran_harian')
                        ->where('target_id', $id);
                })->orWhere(function ($q) use ($id) {
                    $q->where('target_type', 'setoran_detail')
                        ->where('detail_perubahan->setoran_harian_id', $id);
                });
            } else {
                $outer->orWhere(function ($q) use ($search) {
                    $q->where('target_type', 'setoran_detail')
                        ->where('detail_perubahan->entry->metode_pembayaran', 'like', "%{$search}%")
                        ->orWhere('detail_perubahan->metode_pembayaran', 'like', "%{$search}%");
                });
            }
        });
    }

    /**
     * Bagian 2.6 -- resolusi target di-batch per target_type (whereIn),
     * BUKAN query satu-satu per baris (N+1). Juga siapin peta nama loket
     * buat baris mutasi_loket_terdeteksi (loket_lama/loket_baru itu ID,
     * bukan target_loket_id, jadi butuh batch terpisah).
     *
     * @return array{0: Collection, 1: Collection, 2: Collection, 3: Collection}
     */
    private function siapkanPetaBatch(Collection $items): array
    {
        $setoranHarianIds = $items->where('target_type', 'setoran_harian')
            ->pluck('target_id')->filter()->unique()->values();

        $setoranDetailIds = $items->where('target_type', 'setoran_detail')
            ->pluck('target_id')->filter()->unique()->values();

        $userIds = $items->where('target_type', 'users')
            ->pluck('target_id')->filter()->unique()->values();

        $loketMutasiIds = $items->where('jenis_aksi', 'mutasi_loket_terdeteksi')
            ->flatMap(fn (ActivityLog $log) => [
                $log->detail_perubahan['loket_lama'] ?? null,
                $log->detail_perubahan['loket_baru'] ?? null,
            ])
            ->filter()->unique()->values();

        return [
            SetoranHarian::whereIn('id', $setoranHarianIds)->get()->keyBy('id'),
            SetoranDetail::whereIn('id', $setoranDetailIds)->get()->keyBy('id'),
            User::whereIn('id', $userIds)->get()->keyBy('id'),
            Loket::whereIn('loket_id', $loketMutasiIds)->pluck('nama_loket', 'loket_id'),
        ];
    }

    /**
     * Bagian 2.6. Fallback ke snapshot di detail_perubahan kalau row
     * targetnya sendiri sudah hard-delete (setoran_dihapus / hapus_setoran)
     * -- live lookup ke tabel aslinya pasti gagal buat 2 jenis aksi itu.
     */
    private function resolveTarget(
        ActivityLog $log,
        Collection $setoranHarianMap,
        Collection $setoranDetailMap,
        Collection $userMap
    ): ?string {
        return match ($log->target_type) {
            'setoran_harian' => $this->resolveTargetSetoranHarian($log, $setoranHarianMap),
            'setoran_detail' => $this->resolveTargetSetoranDetail($log, $setoranDetailMap),
            'users' => $this->resolveTargetUser($log, $userMap),
            default => null,
        };
    }

    private function resolveTargetSetoranHarian(ActivityLog $log, Collection $map): ?string
    {
        $sh = $log->target_id !== null ? $map->get($log->target_id) : null;

        if ($sh !== null) {
            return 'Setoran ' . $sh->tanggal_transaksi->toDateString();
        }

        // Row sudah dihapus (aksi setoran_dihapus) -- fallback ke snapshot
        // yang sudah tersimpan di detail_perubahan log ini sendiri.
        $tanggal = $log->detail_perubahan['tanggal_transaksi'] ?? null;

        return $tanggal !== null ? "Setoran {$tanggal}" : null;
    }

    private function resolveTargetSetoranDetail(ActivityLog $log, Collection $map): ?string
    {
        $sd = $log->target_id !== null ? $map->get($log->target_id) : null;

        if ($sd !== null) {
            return "Detail Setoran #{$sd->id} ({$sd->metode_pembayaran})";
        }

        // Row sudah dihapus (aksi hapus_setoran) -- fallback ke snapshot
        // 'entry' yang sudah tersimpan di detail_perubahan log ini sendiri.
        $entry = $log->detail_perubahan['entry'] ?? null;

        if ($entry !== null && isset($entry['metode_pembayaran'])) {
            return "Detail Setoran #{$log->target_id} ({$entry['metode_pembayaran']})";
        }

        return null;
    }

    private function resolveTargetUser(ActivityLog $log, Collection $map): ?string
    {
        $u = $log->target_id !== null ? $map->get($log->target_id) : null;

        return $u !== null ? "User: {$u->nama}" : null;
    }

    /**
     * Bagian 2.7 -- mapping PER jenis_aksi, bukan diff generik. Lihat
     * tabel lengkap di dokumen buat alasan tiap perlakuan.
     */
    private function resolvePerubahan(ActivityLog $log, Collection $loketMutasiMap): array
    {
        $dp = $log->detail_perubahan ?? [];

        return match ($log->jenis_aksi) {
            // FIX (audit Log Aktivitas): ubah_kapabilitas nulis
            // detail_perubahan dengan shape sebelum/sesudah yang PERSIS
            // sama kayak edit_setoran/submit_setoran (lihat
            // HakAksesController::update baris ~193), tapi kelewat gak
            // diikutkan ke sini -- akibatnya jatuh ke default => [] dan
            // admin gak pernah lihat kapabilitas apa yang berubah dari
            // apa ke apa, walau datanya beneran tersimpan.
            'edit_setoran', 'submit_setoran', 'ubah_kapabilitas' => $this->diffSebelumSesudah(
                $dp['sebelum'] ?? [],
                $dp['sesudah'] ?? []
            ),
            'hapus_setoran' => $this->perubahanHapusSetoran($dp),
            'setoran_dihapus' => $this->perubahanSetoranDihapus($dp),
            'mutasi_loket_terdeteksi' => $this->perubahanMutasiLoket($dp, $loketMutasiMap),
            default => [], // buat_setoran, buat_pelunasan, auto_provisioning,
            // vendor_baru_terdeteksi, login_manual, login_gagal,
            // logout_manual, riwayat_lintas_loket_dilihat/diekspor,
            // atur_password (detail_perubahan sengaja tidak berisi data
            // sensitif buat didiff), laporan_dilihat/diekspor/diunduh
            // (aksi lihat/ekspor, tidak ada state sebelum/sesudah)
        };
    }

    /**
     * Cuma masukin field yang NILAINYA BEDA (Bagian 2.7: "field yang
     * berubah", bukan semua field yang ada di sebelum/sesudah).
     */
    private function diffSebelumSesudah(array $sebelum, array $sesudah): array
    {
        $labels = array_unique(array_merge(array_keys($sebelum), array_keys($sesudah)));

        $hasil = [];
        foreach ($labels as $label) {
            $before = $sebelum[$label] ?? null;
            $after = $sesudah[$label] ?? null;

            if ($before === $after) {
                continue;
            }

            $hasil[] = [
                'label' => $label,
                'before' => $this->formatNilai($before),
                'after' => $this->formatNilai($after),
            ];
        }

        return $hasil;
    }

    /**
     * Bagian 2.7.1 -- flatten key `entry` jadi per-field, before = nilai
     * yang hilang, after = null (data sudah hard-delete).
     */
    private function perubahanHapusSetoran(array $dp): array
    {
        $entry = $dp['entry'] ?? [];
        $hasil = [];

        foreach (['metode_pembayaran', 'nominal', 'foto_bukti_path'] as $label) {
            if (! array_key_exists($label, $entry)) {
                continue;
            }
            $hasil[] = [
                'label' => $label,
                'before' => $this->formatNilai($entry[$label]),
                'after' => null,
            ];
        }

        return $hasil;
    }

    /**
     * Bagian 2.7.1 -- field biasa before=nilai/after=null (data hilang),
     * KECUALI `alasan` yang dibalik arahnya (info baru, bukan yang hilang).
     * `loket_id` sengaja di-skip (sudah ada di kolom `loket` terpisah).
     */
    private function perubahanSetoranDihapus(array $dp): array
    {
        $hasil = [];

        foreach (['tanggal_transaksi', 'status_saat_dihapus', 'jumlah_entry_dihapus'] as $label) {
            if (! array_key_exists($label, $dp)) {
                continue;
            }
            $hasil[] = [
                'label' => $label,
                'before' => $this->formatNilai($dp[$label]),
                'after' => null,
            ];
        }

        if (! empty($dp['alasan'])) {
            $hasil[] = [
                'label' => 'Alasan Penghapusan',
                'before' => null,
                'after' => $dp['alasan'],
            ];
        }

        return $hasil;
    }

    /**
     * Bagian 2.7.3 -- loket_lama/loket_baru itu ID, di-resolve ke nama
     * lewat peta yang sudah di-batch di siapkanPetaBatch().
     */
    private function perubahanMutasiLoket(array $dp, Collection $loketMutasiMap): array
    {
        $loketLamaId = $dp['loket_lama'] ?? null;
        $loketBaruId = $dp['loket_baru'] ?? null;

        return [[
            'label' => 'Loket',
            'before' => $loketLamaId !== null ? ($loketMutasiMap->get($loketLamaId)) : null,
            'after' => $loketBaruId !== null ? ($loketMutasiMap->get($loketBaruId)) : null,
        ]];
    }

    /**
     * Bagian 2.7 -- boolean jadi "Ya"/"Tidak" (kontrak before/after
     * bertipe String | null, bukan boolean mentah).
     */
    private function formatNilai(mixed $nilai): ?string
    {
        if (is_bool($nilai)) {
            return $nilai ? 'Ya' : 'Tidak';
        }

        return $nilai === null ? null : (string) $nilai;
    }
}
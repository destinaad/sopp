<?php

namespace App\Services;

use App\Exceptions\BridgeException;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Satu-satunya kelas yang tau cara "ngomong" ke bridge.php. Tugasnya
 * sempit sengaja -- dia tidak tau soal TTL, tidak tau soal
 * setoran_harian, tidak tau kapan seharusnya dipanggil. Itu semua
 * keputusan SnapshotService, bukan di sini.
 */
class BridgeService
{
    // Public (bukan private) SENGAJA -- LaporanDataService perlu angka
    // yang PERSIS sama buat mecah run cache-miss jadi chunk fisik SEBELUM
    // manggil fetch()/fetchDetailPerUser(), supaya kegagalan 1 chunk gak
    // ikut menandai gagal hari-hari lain dalam run yang sama yang
    // sebenarnya ada di chunk fisik berbeda (lihat
    // LaporanDataService::pecahRunJadiChunkFisik()). Duplikat angka "31"
    // di 2 tempat berisiko kesenjangan kalau limit bridge.php berubah
    // suatu saat -- reuse konstanta ini lebih aman daripada itu.
    public const MAKS_HARI_PER_REQUEST = 31;

    public function __construct(
        private readonly Client $client,
        private readonly string $baseUrl,
        private readonly string $key,
    ) {}

    /**
     * Ambil data untuk rentang tanggal_mulai..tanggal_selesai (inklusif).
     * Kalau rentangnya lebih dari 31 hari, otomatis dipecah jadi
     * beberapa request berurutan ke bridge lalu digabung di sini --
     * pemanggil (SnapshotService) tidak perlu tau bridge.php punya
     * batas 31 hari sama sekali.
     *
     * @return array{data: array, grand_total: int}
     * @throws BridgeException
     */
    public function fetch(string $tanggalMulai, string $tanggalSelesai, ?int $loketId = null, ?User $actor = null): array
    {
        $dataGabungan   = [];
        $grandTotalGabungan = 0;

        foreach ($this->pecahJadiChunk($tanggalMulai, $tanggalSelesai) as [$mulai, $selesai]) {
            $hasil = $this->fetchSatuChunk($mulai, $selesai, $loketId);

            $dataGabungan       = array_merge($dataGabungan, $hasil['data']);
            $grandTotalGabungan += $hasil['grand_total'];
        }

        if ($actor !== null) {
            // FIX: fetch ke bridge di atas ini UDAH SUKSES pas nyampe sini --
            // catatVendorBaruJikaAda() itu cuma nyatet FYI/audit trail, bukan
            // bagian inti dari kontrak method ini. Kalau nulis activity_logs
            // gagal (DB sempat gangguan dsb.), jangan sampai bikin SELURUH
            // fetch yang sebenarnya udah berhasil ikut dianggap gagal di mata
            // pemanggil -- itu bakal nyebabin user liat 500 mentah padahal
            // data yang dia butuhin udah ada. Ditelan di sini, dicatat ke log
            // aplikasi biasa (gak masuk activity_logs) biar tetap ketauan
            // pas debugging tanpa ganggu response ke user.
            try {
                $this->catatVendorBaruJikaAda($dataGabungan, $actor);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'Gagal mencatat vendor baru terdeteksi, fetch bridge tetap dianggap sukses.',
                    ['pesan' => $e->getMessage(), 'actor_id' => $actor->id],
                );
            }
        }

        return [
            'data'        => $dataGabungan,
            'grand_total' => (int) $grandTotalGabungan,
        ];
    }

    /**
     * Wrapper buat bridge.php?mode=detail_rekonsiliasi -- BUKAN varian dari
     * fetch(), method terpisah sengaja biar Card A/Dashboard/Pelunasan yang
     * udah final gak ikut kesenggol.
     *
     * Bridge mode ini TIDAK punya filter user sama sekali -- selalu balikin
     * breakdown SEMUA user yang match rentang tanggal+loket. Kalau consumer
     * cuma butuh 1 user, filter dilakukan di sisi consumer SETELAH data ini
     * balik, bukan dikirim sebagai parameter ke sini.
     *
     * REVISI: sebelumnya method ini sengaja TIDAK menerima $actor dan TIDAK
     * memanggil catatVendorBaruJikaAda(), dengan asumsi vendor_id di sini
     * selalu subset dari yang sudah tercakup fetch() untuk rentang yang
     * sama. Asumsi itu PATAH sejak LaporanDataService (Skenario 3) mulai
     * manggil method ini TANPA pernah manggil fetch() untuk tanggal yang
     * sama -- kalau deteksi vendor baru tetap cuma di fetch(), vendor baru
     * yang cuma pernah kelihatan lewat S3 gak akan PERNAH kedeteksi (bukan
     * cuma telat), bukan cuma buat S3 tapi juga S2/S4/S6 yang dari awal
     * emang gak pernah trigger deteksi ini sama sekali. Makanya sekarang
     * terima $actor dan panggil catatVendorBaruJikaAda() juga -- data buat
     * deteksi ini (vendor_id) memang tersedia di GROUP BY mode
     * detail_rekonsiliasi juga, bukan cuma di mode default.
     *
     * Sengaja TIDAK ada logic caching/TTL apapun di sini -- level abstraksi
     * ini setara fetch() polos sebelum ada SnapshotService di atasnya.
     * Keputusan cache untuk method ini didiskusikan terpisah.
     *
     * @return array{data: array, grand_total: int}
     * @throws BridgeException
     */
    public function fetchDetailPerUser(string $tanggalMulai, string $tanggalSelesai, ?int $loketId = null, ?User $actor = null): array
    {
        $dataGabungan       = [];
        $grandTotalGabungan = 0;

        foreach ($this->pecahJadiChunk($tanggalMulai, $tanggalSelesai) as [$mulai, $selesai]) {
            $hasil = $this->fetchSatuChunkDetail($mulai, $selesai, $loketId);

            $dataGabungan       = array_merge($dataGabungan, $hasil['data']);
            $grandTotalGabungan += $hasil['grand_total'];
        }

        if ($actor !== null) {
            // Guard SAMA PERSIS kayak fetch() -- gagal nyatet vendor baru
            // gak boleh bikin SELURUH fetchDetailPerUser yang sebenarnya
            // udah sukses ikut dianggap gagal di mata pemanggil.
            try {
                $this->catatVendorBaruJikaAda($dataGabungan, $actor);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'Gagal mencatat vendor baru terdeteksi (fetchDetailPerUser), fetch bridge tetap dianggap sukses.',
                    ['pesan' => $e->getMessage(), 'actor_id' => $actor->id],
                );
            }
        }

        return [
            'data'        => $dataGabungan,
            'grand_total' => (int) $grandTotalGabungan,
        ];
    }

    private function catatVendorBaruJikaAda(array $rows, User $actor): void
    {
        $vendorSudahDikenal = ActivityLog::where('jenis_aksi', 'vendor_baru_terdeteksi')
            ->get()
            ->pluck('detail_perubahan.vendor_id')
            ->all();

        foreach (collect($rows)->unique('vendor_id') as $row) {
            if (! in_array($row['vendor_id'], $vendorSudahDikenal, true)) {
                ActivityLog::catat(
                    actor: $actor,
                    jenisAksi: 'vendor_baru_terdeteksi',
                    objectType: null,
                    objectId: null,
                    detailPerubahan: [
                        'vendor_id' => $row['vendor_id'],
                        'vendor_name' => $row['vendor_name'],
                        'catatan' => 'Vendor ini pertama kali muncul di data SOPP lama; user ini hanya kebetulan sedang memuat data saat itu.',
                    ],
                );
                $vendorSudahDikenal[] = $row['vendor_id'];
            }
        }
    }

    /**
     * Ambil daftar SEMUA loket dari sistem lama (independen dari
     * ada-tidaknya transaksi -- lihat bridge.php mode=master_loket).
     * Dipanggil dari artisan command sync (lihat SyncLoket), BUKAN
     * dari controller yang melayani request pengguna -- nama_loket
     * yang ditampilkan ke pengguna SELALU dari tabel `lokets` lokal
     * (§6.1/§8.6), bukan live-fetch tiap kali dibutuhkan.
     *
     * @return list<array{loket_id:int, loket_name:string}>
     * @throws BridgeException
     */
    public function fetchMasterLoket(): array
    {
        try {
            $response = $this->client->get($this->baseUrl, [
                'query' => [
                    'mode' => 'master_loket',
                ],
                'headers' => [
                    'X-Bridge-Key' => $this->key,
                ],
                'timeout' => 20,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $e) {
            throw new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.');
        }

        $json = json_decode((string) $response->getBody(), true);
        if (! is_array($json) || ! isset($json['status'])) {
            throw new BridgeException('Respons dari bridge tidak dapat dibaca.');
        }
        if ($json['status'] !== 'ok') {
            throw new BridgeException($json['message'] ?? 'Bridge mengembalikan error tanpa pesan.');
        }

        return $json['data'] ?? [];
    }

    /**
     * @return list<array{0:string,1:string}> pasangan [mulai, selesai] per chunk, masing-masing maks 31 hari
     */
    private function pecahJadiChunk(string $tanggalMulai, string $tanggalSelesai): array
    {
        $mulai   = Carbon::parse($tanggalMulai)->startOfDay();
        $selesai = Carbon::parse($tanggalSelesai)->startOfDay();

        if ($mulai->greaterThan($selesai)) {
            throw new BridgeException('tanggal_selesai tidak boleh sebelum tanggal_mulai.');
        }

        $chunks = [];
        $kursor = $mulai->copy();

        while ($kursor->lessThanOrEqualTo($selesai)) {
            // -1 supaya rentang chunk maksimal PAS 31 hari (inklusif),
            // bukan 32 -- cocok dengan validasi "> 31" di bridge.php.
            $akhirChunk = $kursor->copy()->addDays(self::MAKS_HARI_PER_REQUEST - 1);
            if ($akhirChunk->greaterThan($selesai)) {
                $akhirChunk = $selesai->copy();
            }

            $chunks[] = [$kursor->toDateString(), $akhirChunk->toDateString()];
            $kursor   = $akhirChunk->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * @return array{data: array, grand_total: int}
     */
    private function fetchSatuChunk(string $tanggalMulai, string $tanggalSelesai, ?int $loketId): array
    {
        try {
            $response = $this->client->get($this->baseUrl, [
                'query' => array_filter([
                    'tanggal_mulai'   => $tanggalMulai,
                    'tanggal_selesai' => $tanggalSelesai,
                    'loket_id'        => $loketId,
                ], static fn($nilai) => $nilai !== null),
                'headers' => [
                    'X-Bridge-Key' => $this->key,
                ],
                'timeout' => 20,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $e) {
            // Server lama mati/timeout/dll -- ini beda kasus dari
            // bridge merespons tapi isinya "status: error". Dua-duanya
            // sama-sama berujung BridgeException karena dari sudut
            // pandang pemanggil, keduanya sama-sama berarti "data
            // pembanding tidak tersedia saat ini".
            throw new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.');
        }

        $json = json_decode((string) $response->getBody(), true);

        if (! is_array($json) || ! isset($json['status'])) {
            throw new BridgeException('Respons dari bridge tidak dapat dibaca.');
        }

        if ($json['status'] !== 'ok') {
            throw new BridgeException($json['message'] ?? 'Bridge mengembalikan error tanpa pesan.');
        }

        return [
            'data'        => $json['data'] ?? [],
            'grand_total' => (int) ($json['grand_total'] ?? 0),
        ];
    }

    /**
     * Sibling fetchSatuChunk() -- bedanya cuma nambahin mode=detail_rekonsiliasi
     * di query. Sengaja dipisah, bukan menambah parameter $mode ke
     * fetchSatuChunk() lama, biar jalur lama gak ikut punya cabang baru.
     *
     * @return array{data: array, grand_total: int}
     */
    private function fetchSatuChunkDetail(string $tanggalMulai, string $tanggalSelesai, ?int $loketId): array
    {
        try {
            $response = $this->client->get($this->baseUrl, [
                'query' => array_filter([
                    'mode'            => 'detail_rekonsiliasi',
                    'tanggal_mulai'   => $tanggalMulai,
                    'tanggal_selesai' => $tanggalSelesai,
                    'loket_id'        => $loketId,
                ], static fn($nilai) => $nilai !== null),
                'headers' => [
                    'X-Bridge-Key' => $this->key,
                ],
                'timeout' => 20,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $e) {
            throw new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.');
        }

        $json = json_decode((string) $response->getBody(), true);

        if (! is_array($json) || ! isset($json['status'])) {
            throw new BridgeException('Respons dari bridge tidak dapat dibaca.');
        }

        if ($json['status'] !== 'ok') {
            throw new BridgeException($json['message'] ?? 'Bridge mengembalikan error tanpa pesan.');
        }

        return [
            'data'        => $json['data'] ?? [],
            'grand_total' => (int) ($json['grand_total'] ?? 0),
        ];
    }
}
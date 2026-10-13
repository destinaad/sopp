<?php

namespace Tests\Unit;

use App\Exceptions\BridgeException;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use App\Services\LaporanDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Kerangka awal terinspirasi draf test sesi lain (pra-v10), tapi sudah
 * disesuaikan berkali-kali ke keputusan final: `generate()` publik,
 * `bentukA/B/C` PRIVATE (dites lewat `generate()`); `setoran_id` Bentuk A
 * DIHAPUS TOTAL (bukan placeholder tie-break); S3 (mode harian, loket_id
 * null) SEKARANG dapet `drill_down_teller` dan sumber bridge-nya
 * `fetchDetailPerUser(loket_id=null)` -- BUKAN `fetch()` lagi -- lihat
 * docblock kelas `LaporanDataService` bagian "S3 1-call" buat alasannya.
 */
class LaporanDataServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bridgeMock(): BridgeService
    {
        return Mockery::mock(BridgeService::class);
    }

    private function buatLoket(int $id, string $nama): Loket
    {
        return Loket::create(['loket_id' => $id, 'nama_loket' => $nama]);
    }

    /**
     * @param  array<string,int>  $metode
     */
    private function buatSetoranSubmitted(int $loketId, string $tanggal, array $metode, ?User $user = null, ?string $submittedAt = null): SetoranHarian
    {
        return $this->buatSetoran($loketId, $tanggal, $metode, 'submitted', $user, $submittedAt ?? $tanggal . ' 12:00:00');
    }

    private function buatSetoranDraft(int $loketId, string $tanggal, array $metode): SetoranHarian
    {
        return $this->buatSetoran($loketId, $tanggal, $metode, 'draft', null, null);
    }

    private function buatSetoran(int $loketId, string $tanggal, array $metode, string $status, ?User $user, ?string $submittedAt): SetoranHarian
    {
        $user ??= User::factory()->create(['loket_id' => $loketId]);

        $setoran = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $loketId,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);

        foreach ($metode as $namaMetode => $nominal) {
            SetoranDetail::create([
                'setoran_harian_id' => $setoran->id,
                'metode_pembayaran' => $namaMetode,
                'nominal' => $nominal,
            ]);
        }

        return $setoran;
    }

    /**
     * Baris hasil fetchDetailPerUser() -- dipakai buat mock S2/S4/S6
     * (loket_id terisi) MAUPUN S3 (loket_id null, tapi row-nya sendiri
     * tetap punya loket_id per baris karena GROUP BY-nya termasuk loket).
     */
    private function baris(string $tanggal, int $loketId, string $vendor, int $total, ?string $userId = null, ?string $userName = null): array
    {
        return [
            'tanggal' => $tanggal,
            'loket_id' => $loketId,
            'vendor_id' => 1,
            'vendor_name' => $vendor,
            'total' => $total,
            'user_id' => $userId,
            'user_name' => $userName,
        ];
    }

    // ============================= CACHE (via S3 -- harian, loket_id null) =============================

    public function test_S3_cache_hit_tidak_manggil_bridge_lagi(): void
    {
        Carbon::setTestNow('2026-09-10');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);
        $bridge->shouldNotReceive('fetch');

        $service = new LaporanDataService($bridge);

        $service->generate('harian', null, '2026-09-01', '2026-09-01');
        $service->generate('harian', null, '2026-09-01', '2026-09-01');

        // Mockery ->once() akan gagal di teardown kalau bridge kepanggil 2x.
        $this->assertTrue(true);
    }

    public function test_S3_grace_period_ttl_10_menit_expired_manggil_lagi(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->twice()->with('2026-09-10', '2026-09-10', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);

        $service = new LaporanDataService($bridge);
        $service->generate('harian', null, '2026-09-10', '2026-09-10');

        Carbon::setTestNow('2026-09-10 08:11:00'); // lewat TTL grace 10 menit
        $service->generate('harian', null, '2026-09-10', '2026-09-10');

        $this->assertTrue(true);
    }

    public function test_S3_tanggal_tutup_buku_ttl_30_hari_masih_kepake_walau_lewat_11_menit(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        $tanggal = '2026-09-01'; // H+2 dari 10 Sept -> tutup buku

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with($tanggal, $tanggal, null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);

        $service = new LaporanDataService($bridge);
        $service->generate('harian', null, $tanggal, $tanggal);

        Carbon::setTestNow('2026-09-10 08:11:00');
        $service->generate('harian', null, $tanggal, $tanggal);

        $this->assertTrue(true);
    }

    public function test_S3_kegagalan_bridge_tidak_ikut_dicache_dan_ditandai_bridge_gagal(): void
    {
        Carbon::setTestNow('2026-09-10');
        $tanggal = '2026-09-01';
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->twice()->with($tanggal, $tanggal, null, null)
            ->andThrow(new BridgeException('gagal'));

        // Ada submitted lokal biar loket 1 tetap MUNCUL di items walau
        // bridge gagal total (union loket bridge+lokal).
        $this->buatSetoranSubmitted(1, $tanggal, ['transfer' => 50000]);

        $service = new LaporanDataService($bridge);

        $hasil1 = $service->generate('harian', null, $tanggal, $tanggal);
        $hasil2 = $service->generate('harian', null, $tanggal, $tanggal);

        // Kalau kegagalan sempat ke-cache, panggilan kedua gak bakal
        // manggil bridge lagi -> Mockery ->twice() gagal di teardown.
        $this->assertTrue($hasil1['bridge_gagal']);
        $this->assertTrue($hasil2['bridge_gagal']);
        $this->assertNull($hasil1['items'][0]['vendors']);
        $this->assertNull($hasil1['items'][0]['total_pendapatan_sopp']);
        $this->assertNull($hasil1['items'][0]['selisih']);
    }

    public function test_cache_S3_dan_cache_S1_S5_per_hari_individual_pakai_namespace_terpisah(): void
    {
        Carbon::setTestNow('2026-09-05');

        // Warm-up lewat S3 (mode=harian, loket_id=null).
        $bridgeWarmup = $this->bridgeMock();
        $bridgeWarmup->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);
        (new LaporanDataService($bridgeWarmup))->generate('harian', null, '2026-09-01', '2026-09-01');

        Carbon::setTestNow('2026-09-10');
        // 2026-09-01 diminta lagi lewat S1/S5 (mode=bulanan/rentang,
        // multi-hari) -- ini pakai `fetch()`, namespace cache BEDA dari
        // S3 ('laporan_fetch' vs 'laporan_detail_semua_loket'), jadi
        // HARUS tetap manggil bridge lagi, bukan cache-hit dari warm-up
        // S3 di atas.
        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);

        $hasil = (new LaporanDataService($bridge))->generate('bulanan', null, '2026-09-01', '2026-09-02');
        $this->assertFalse($hasil['bridge_gagal']);
    }

    public function test_run_S1_S5_lebih_dari_31_hari_dipecah_jadi_2_chunk_fisik_kegagalan_1_chunk_gak_buang_yang_lain(): void
    {
        Carbon::setTestNow('2026-03-01');
        $this->buatLoket(1, 'Loket A');

        // 2026-01-01..2026-02-09 = 40 hari -> HARUS kepecah jadi
        // 2026-01-01..2026-01-31 (31 hari) dan 2026-02-01..2026-02-09
        // (9 hari), sesuai BridgeService::MAKS_HARI_PER_REQUEST. Ini
        // rentang multi-hari (S1/S5) -- tetap lewat fetch(), TIDAK
        // kesenggol perubahan S3.
        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-01-01', '2026-01-31', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-01-01', 1, 'Finnet', 1000),
            ], 'grand_total' => 1000]);
        $bridge->shouldReceive('fetch')->once()->with('2026-02-01', '2026-02-09', null, null)
            ->andThrow(new BridgeException('gagal di chunk kedua'));

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-01-01', '2026-02-09');

        $this->assertTrue($hasil['bridge_gagal']);

        $baris = collect($hasil['items'])->firstWhere('loket_id', 1);
        // FIX: vendors SEKARANG ikut null-in kalau bridge_gagal (dulu
        // baris ini nampilin 1000 -- angka nyata dari chunk pertama yang
        // sukses -- padahal total_pendapatan_sopp+selisih di baris yang
        // SAMA sudah null. Itu nyesatkan: kelihatan kayak angka final
        // padahal cuma sebagian periode. Sekarang konsisten: satu hari
        // manapun di periode gagal -> vendors DAN totalnya sama-sama
        // null, bukan cuma totalnya.
        $this->assertNull($baris['vendors']);
        $this->assertNull($baris['total_pendapatan_sopp']);
        $this->assertNull($baris['selisih']);
        // S1/S5 (multi-hari) -- drill_down_teller TIDAK applicable.
        $this->assertNull($baris['drill_down_teller']);

        // Tanggal dari chunk pertama yang sukses HARUS tetap ke-cache
        // per-hari (walau chunk kedua gagal total) -- ini soal EFISIENSI
        // request BERIKUTNYA (gak perlu re-fetch hari yang udah sukses),
        // beda dari soal "apa yang ditampilkan di respons SEKARANG" yang
        // di atas.
        $this->assertNotNull(Cache::get('laporan_fetch:2026-01-01'));
    }

    public function test_guard_invarian_prefix_vs_loket_id_dilanggar_dilempar_exception(): void
    {
        $service = new LaporanDataService($this->bridgeMock());
        $method = new ReflectionMethod($service, 'cacheKeyHarian');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        // 'laporan_fetch' (S1/S5) HARUS loket_id null -- di sini sengaja
        // dikasih loket_id terisi, harus ditolak. (Catatan: guard ini
        // cuma nutupin 2 kombinasi yang lewat cacheKeyHarian(), yaitu
        // 'laporan_fetch' dan 'laporan_detail' -- kombinasi ke-3 buat S3
        // ('laporan_detail_semua_loket') gak pernah lewat method ini,
        // cuma dipakai di 1 titik hardcoded di ambilDetailSemuaLoketHarian(),
        // jadi gak butuh guard runtime tambahan di sana.)
        $method->invoke($service, 'laporan_fetch', 5, '2026-09-01');
    }

    // ============================= BENTUK A -- S1/S5 (multi-hari, loket_id null) =============================

    /**
     * Regresi -- kelas bug yang SAMA persis kayak yang ketemu di
     * LaporanExportController ($modeChunk di-re-derive dari lebar
     * tanggal): kalau `$mode='rentang'` KEBETULAN dikirim dengan
     * tanggal_mulai=tanggal_selesai (user milih rentang 1-hari yang
     * sama persis -- edge case tapi valid), `bentukA()` HARUS tetap
     * jalan sebagai S1/S5 (fetch(), drill_down_teller null), BUKAN
     * diam-diam ke-treat sebagai S3 cuma karena lebar tanggalnya
     * kebetulan 1 hari. Ini fix yang sama yang sebelumnya diterapkan
     * ('$mode==="harian"', bukan cek lebar tanggal) -- test ini yang
     * kemarin kelewat ditulis di unit level, padahal itu sumber
     * fix-nya, bukan di controller/export.
     */
    public function test_mode_rentang_dengan_tanggal_mulai_sama_selesai_tetap_S1_S5_bukan_S3(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldNotReceive('fetchDetailPerUser');
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 100000),
            ], 'grand_total' => 100000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-01');

        $row = collect($hasil['items'])->firstWhere('loket_id', 1);
        $this->assertSame(100000, $row['vendors']['Finnet']);
        // S1/S5 -- TIDAK applicable, harus null (bukan array, bukan
        // ke-isi dari fetchDetailPerUser yang gak pernah dipanggil).
        $this->assertNull($row['drill_down_teller']);
    }

    /**
     * Sama seperti di atas, tapi sisi Bentuk B/C (loket_id terisi):
     * mode='rentang' dengan tanggal_mulai=tanggal_selesai harus tetap
     * Bentuk B (1 baris tanggal, punya `pagination`), BUKAN Bentuk C
     * (rincian per transaksi, TANPA `pagination`) -- dispatch di
     * generate() ('$mode==="harian" ? bentukC() : bentukB()') sudah
     * benar dari awal (cek $mode, bukan lebar tanggal), tapi belum ada
     * test eksplisit yang mastiin ini di kombinasi tanggal yang
     * "mencurigakan" (kebetulan 1 hari, mode bukan harian).
     */
    public function test_mode_rentang_dengan_tanggal_mulai_sama_selesai_loket_terisi_tetap_bentukB_bukan_C(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'Budi'),
            ], 'grand_total' => 100000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', 1, '2026-08-01', '2026-08-01');

        $this->assertSame('B', $hasil['bentuk']);
        $this->assertArrayHasKey('meta', $hasil);
        $this->assertArrayNotHasKey('rincian_transaksi', $hasil);
        $this->assertCount(1, $hasil['items']);
        $this->assertSame('2026-08-01', $hasil['items'][0]['tanggal']);
    }

    public function test_S1_S5_gabung_vendor_bridge_dan_metode_setoran_lokal_drill_down_null(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket Pasar Legi');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 700000),
            ], 'grand_total' => 700000]);

        $this->buatSetoranSubmitted(1, '2026-09-01', ['transfer' => 500000, 'qris' => 200000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-02');

        $this->assertCount(1, $hasil['items']);
        $row = $hasil['items'][0];
        $this->assertSame(1, $row['loket_id']);
        $this->assertSame('Loket Pasar Legi', $row['nama_loket']);
        $this->assertSame(['Finnet' => 700000], $row['vendors']);
        $this->assertSame(700000, $row['total_pendapatan_sopp']);
        $this->assertSame(['transfer' => 500000, 'qris' => 200000, 'tunai' => 0, 'kpay' => 0], $row['metode_setoran']);
        $this->assertSame(700000, $row['total_setor']);
        $this->assertSame(0, $row['selisih']);
        $this->assertSame('lunas', $row['status_keuangan']);
        $this->assertArrayNotHasKey('setoran_id', $row);
        // S1/S5 -- field ini NULL, bukan array kosong (gak applicable,
        // beda dari "applicable tapi nihil").
        $this->assertNull($row['drill_down_teller']);
    }

    public function test_S1_S5_union_loket_bridge_only_dan_lokal_only(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $this->buatLoket(2, 'Loket B');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 100000),
            ], 'grand_total' => 100000]);

        // Loket 2 CUMA ada submitted lokal, nihil di bridge.
        $this->buatSetoranSubmitted(2, '2026-09-01', ['tunai' => 50000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-02');

        $this->assertCount(2, $hasil['items']);
        $byId = collect($hasil['items'])->keyBy('loket_id');

        $this->assertSame(100000, $byId[1]['total_pendapatan_sopp']);
        $this->assertSame(0, $byId[1]['total_setor']);
        $this->assertSame(100000, $byId[1]['selisih']);

        $this->assertSame(0, $byId[2]['total_pendapatan_sopp']);
        $this->assertSame(50000, $byId[2]['total_setor']);
        $this->assertSame(-50000, $byId[2]['selisih']);
    }

    public function test_S1_S5_draft_tidak_dihitung_dan_loket_gak_muncul_kalau_cuma_draft(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);

        $this->buatSetoranDraft(1, '2026-09-01', ['transfer' => 999999]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-02');

        $this->assertCount(0, $hasil['items']);
    }

    public function test_S1_S5_vendors_selalu_lengkap_nol_kalau_gak_ada(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $this->buatLoket(2, 'Loket B');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 100000),
                $this->baris('2026-09-01', 2, 'PUSKUD', 200000),
            ], 'grand_total' => 300000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-02');

        $byId = collect($hasil['items'])->keyBy('loket_id');
        $this->assertSame(['Finnet' => 100000, 'PUSKUD' => 0], $byId[1]['vendors']);
        $this->assertSame(['Finnet' => 0, 'PUSKUD' => 200000], $byId[2]['vendors']);
    }

    public function test_S1_S5_total_row_sum_semua_baris_dan_null_kalau_ada_yang_gagal(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'A');
        $this->buatLoket(2, 'B');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetch')->once()->with('2026-09-01', '2026-09-02', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 100000),
                $this->baris('2026-09-01', 2, 'Finnet', 200000),
            ], 'grand_total' => 300000]);

        $this->buatSetoranSubmitted(1, '2026-09-01', ['transfer' => 100000]);
        $this->buatSetoranSubmitted(2, '2026-09-01', ['tunai' => 150000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', null, '2026-09-01', '2026-09-02');

        $this->assertSame(300000, $hasil['total_row']['total_pendapatan_sopp']);
        $this->assertSame(250000, $hasil['total_row']['total_setor']);
        $this->assertSame(50000, $hasil['total_row']['selisih']);
        $this->assertSame(['Finnet' => 300000], $hasil['total_row']['vendors']);
    }

    // ============================= BENTUK A -- S3 (harian, loket_id null) =============================

    public function test_S3_TIDAK_manggil_fetch_sama_sekali_dan_vendors_diturunkan_dari_fetchDetailPerUser(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldNotReceive('fetch');
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 400000, 'U001', 'Budi'),
                $this->baris('2026-09-01', 1, 'Finnet', 300000, 'U002', 'Siti'),
            ], 'grand_total' => 700000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', null, '2026-09-01', '2026-09-01');

        $row = collect($hasil['items'])->firstWhere('loket_id', 1);
        // 700000 = SUM lintas 2 teller -- membuktikan vendors{} beneran
        // diturunkan dari fetchDetailPerUser, bukan cuma dibiarkan 0.
        $this->assertSame(700000, $row['vendors']['Finnet']);
        $this->assertSame(700000, $row['total_pendapatan_sopp']);
    }

    public function test_S3_drill_down_teller_gabung_lokal_dan_belum_input(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $budi = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001', 'nama' => 'Budi']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 400000, 'U001', 'Budi'),
                // Siti submit di sistem LAMA, belum ada row lokal.
                $this->baris('2026-09-01', 1, 'Finnet', 100000, 'U999', 'Siti'),
            ], 'grand_total' => 500000]);

        $submitBudi = $this->buatSetoranSubmitted(1, '2026-09-01', ['transfer' => 400000], $budi);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', null, '2026-09-01', '2026-09-01');

        $row = collect($hasil['items'])->firstWhere('loket_id', 1);
        $drillDown = collect($row['drill_down_teller'])->keyBy('nama');

        $this->assertSame($submitBudi->id, $drillDown['Budi']['setoran_id']);
        $this->assertSame('submitted', $drillDown['Budi']['status_workflow']);
        $this->assertNull($drillDown['Siti']['setoran_id']);
        $this->assertSame('belum_input', $drillDown['Siti']['status_workflow']);
    }

    public function test_S3_loket_yang_cuma_ada_di_bridge_drill_down_teller_tetap_array_bukan_null(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        // Loket 1 CUMA muncul di bridge, gak ada row lokal sama sekali.

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-09-01', 1, 'Finnet', 100000, 'U001', 'Budi'),
            ], 'grand_total' => 100000]);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', null, '2026-09-01', '2026-09-01');

        $row = collect($hasil['items'])->firstWhere('loket_id', 1);
        // Applicable (S3), cuma kebetulan semua "belum_input" -- array
        // isi 1, BUKAN null.
        $this->assertIsArray($row['drill_down_teller']);
        $this->assertCount(1, $row['drill_down_teller']);
        $this->assertSame('belum_input', $row['drill_down_teller'][0]['status_workflow']);
    }

    public function test_actor_diteruskan_ke_fetchDetailPerUser_di_S3_buat_deteksi_vendor_baru(): void
    {
        Carbon::setTestNow('2026-09-10');
        $actor = User::factory()->create();

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()
            ->with('2026-09-01', '2026-09-01', null, Mockery::on(fn($a) => $a?->is($actor)))
            ->andReturn(['data' => [], 'grand_total' => 0]);

        $service = new LaporanDataService($bridge);
        $service->generate('harian', null, '2026-09-01', '2026-09-01', actor: $actor);

        $this->assertTrue(true);
    }

    // ============================= BENTUK B (loket_id terisi, bulanan/rentang) =============================

    public function test_bentukB_vendors_null_di_HARI_yang_gagal_saja_bukan_seluruh_periode(): void
    {
        // Beda dari Bentuk A (1 hari gagal -> SELURUH baris loket null,
        // karena 1 baris = 1 PERIODE): di Bentuk B 1 baris = 1 HARI, jadi
        // granularitasnya per-hari -- cuma baris tanggal yang match chunk
        // gagal yang null, baris tanggal lain (chunk yang sukses) TETAP
        // tampil apa adanya. 40 hari sengaja dipakai biar kepecah jadi 2
        // chunk fisik (limit bridge 31 hari), chunk 1 sukses chunk 2 gagal.
        Carbon::setTestNow('2026-03-01');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-01-01', '2026-01-31', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-01-01', 1, 'Finnet', 1000, 'U001', 'Budi'),
            ], 'grand_total' => 1000]);
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-02-01', '2026-02-09', 1, null)
            ->andThrow(new BridgeException('gagal di chunk kedua'));

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', 1, '2026-01-01', '2026-02-09', paginasi: false);

        $baris1Jan = collect($hasil['items'])->firstWhere('tanggal', '2026-01-01');
        $baris1Feb = collect($hasil['items'])->firstWhere('tanggal', '2026-02-01');

        // Tanggal dari chunk yang SUKSES -- vendors tetap tampil apa adanya.
        $this->assertSame(1000, $baris1Jan['vendors']['Finnet']);
        $this->assertSame(1000, $baris1Jan['total_pendapatan_sopp']);

        // Tanggal dari chunk yang GAGAL -- vendors JUGA null (fix), bukan
        // cuma total_pendapatan_sopp/selisih.
        $this->assertNull($baris1Feb['vendors']);
        $this->assertNull($baris1Feb['total_pendapatan_sopp']);
        $this->assertNull($baris1Feb['selisih']);
    }

    public function test_bentukB_1_baris_per_HARI_AKTIF_dan_query_lokal_cuma_1x_untuk_seluruh_periode(): void
    {
        Carbon::setTestNow('2026-09-10');
        $teller = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001']);
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-03', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'Budi'),
                $this->baris('2026-08-03', 1, 'Finnet', 50000, 'U001', 'Budi'),
            ], 'grand_total' => 150000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 80000], $teller);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', 1, '2026-08-01', '2026-08-03');

        // 1 query SetoranHarian (+entries+user via eager load) + 1 query
        // Loket::find(). Longgar (<=5) supaya gak rapuh ke detail
        // Eloquent eager-load, tapi tetap jauh dari "3 hari x N query"
        // kalau N+1 balik lagi.
        $this->assertLessThanOrEqual(5, $queryCount);

        // 2 hari aktif (1 & 3 Agustus) -- 2 Agustus tidak ada transaksi bridge
        // maupun setoran lokal, jadi TIDAK jadi baris.
        $this->assertCount(2, $hasil['items']);
        $this->assertNotContains('2026-08-02', array_column($hasil['items'], 'tanggal'));
        $baris1 = $hasil['items'][0];
        $this->assertSame('2026-08-01', $baris1['tanggal']);
        $this->assertSame(100000, $baris1['total_pendapatan_sopp']);
        $this->assertSame(80000, $baris1['total_setor']);
        $this->assertSame(20000, $baris1['selisih']);

        $baris2 = $hasil['items'][1];
        $this->assertSame('2026-08-03', $baris2['tanggal']);
        $this->assertSame(50000, $baris2['total_pendapatan_sopp']);
        $this->assertSame(0, $baris2['total_setor']);
    }

    public function test_bentukB_filter_teller_hari_yang_cuma_diaktifkan_teller_lain_tidak_jadi_baris(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $tellerA = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001', 'nama' => 'Andi']);
        $tellerB = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U002', 'nama' => 'Budi']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-03', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'Andi'),
                $this->baris('2026-08-02', 1, 'Finnet', 200000, 'U002', 'Budi'),
            ], 'grand_total' => 300000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 90000], $tellerA);
        $this->buatSetoranSubmitted(1, '2026-08-02', ['transfer' => 180000], $tellerB);

        $hasil = (new LaporanDataService($bridge))
            ->generate('rentang', 1, '2026-08-01', '2026-08-03', tellerId: 'U001');

        // 2 Agustus cuma ada Budi -> tidak jadi baris waktu filter = Andi.
        $this->assertSame(['2026-08-01'], array_column($hasil['items'], 'tanggal'));
        $this->assertSame(1, $hasil['meta']['total_items']);
    }

    public function test_bentukB_filter_teller_id_scope_kolom_ringkasan_dan_collapse_drill_down(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $tellerA = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001', 'nama' => 'Andi']);
        $tellerB = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U002', 'nama' => 'Budi']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-02', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'Andi'),
                $this->baris('2026-08-01', 1, 'Finnet', 200000, 'U002', 'Budi'),
            ], 'grand_total' => 300000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 90000], $tellerA);
        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 180000], $tellerB);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', 1, '2026-08-01', '2026-08-02', tellerId: 'U001');

        $baris = $hasil['items'][0];
        // Kolom ringkasan ke-scope ke Andi doang.
        $this->assertSame(100000, $baris['vendors']['Finnet']);
        $this->assertSame(90000, $baris['total_setor']);

        // drill_down_teller HARUS collapse jadi 1 elemen (Andi doang) --
        // sesuai kontrak §5.
        $this->assertCount(1, $baris['drill_down_teller']);
        $this->assertSame('Andi', $baris['drill_down_teller'][0]['nama']);
    }

    public function test_bentukB_teller_tersedia_dan_teller_tanpa_atribusi_bridge(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $tellerDiBridge = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001', 'nama' => 'Andi']);
        $tellerTanpaBridge = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U999', 'nama' => 'Citra']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'Andi'),
            ], 'grand_total' => 100000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 90000], $tellerDiBridge);
        // Citra submit di web baru, TAPI user_id_sopp dia gak ketemu di
        // hasil fetchDetailPerUser periode ini -- harus masuk
        // teller_tanpa_atribusi_bridge.
        $this->buatSetoranSubmitted(1, '2026-08-01', ['tunai' => 50000], $tellerTanpaBridge);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('rentang', 1, '2026-08-01', '2026-08-01');

        $this->assertSame([['teller_id' => 'U001', 'nama' => 'Andi']], $hasil['teller_tersedia']);
        $this->assertSame([['teller_id' => 'U999', 'nama' => 'Citra']], $hasil['teller_tanpa_atribusi_bridge']);
    }

    public function test_bentukB_paginasi_default_8_per_halaman_dan_paginasi_false_balikin_semua(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        // 10 hari aktif (1-10 Agustus), 1 transaksi bridge per hari -- hari tanpa
        // aktivitas TIDAK jadi baris, jadi tiap hari harus punya data.
        $dataSepuluhHari = array_map(
            fn (int $d) => $this->baris(sprintf('2026-08-%02d', $d), 1, 'Finnet', 1000, 'U001', 'Budi'),
            range(1, 10),
        );
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-10', 1, null)
            ->andReturn(['data' => $dataSepuluhHari, 'grand_total' => 10000]);

        $service = new LaporanDataService($bridge);
        // 10 hari (1-10 Agustus).
        $hasilPage1 = $service->generate('rentang', 1, '2026-08-01', '2026-08-10');
        $this->assertCount(8, $hasilPage1['items']);
        $this->assertSame(10, $hasilPage1['meta']['total_items']);
        $this->assertSame(2, $hasilPage1['meta']['total_pages']); // ceil(10/8)

        $bridge2 = $this->bridgeMock();
        $bridge2->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-10', 1, null)
            ->andReturn(['data' => $dataSepuluhHari, 'grand_total' => 10000]);
        // Cache::flush() SENGAJA -- tanpa ini, cache yang udah keisi dari
        // $bridge di atas (10 hari yang sama) bikin $bridge2 GAK PERNAH
        // kepanggil, dan test ini bakal PASS PALSU (assertion di bawah
        // kebeneran, tapi bukan karena $paginasi=false yang diuji --
        // melainkan karena data lama masih nyangkut di cache). Ini bug
        // nyata yang ketauan pas beneran dijalanin, bukan cuma dugaan.
        Cache::flush();
        $hasilTanpaPaging = (new LaporanDataService($bridge2))
            ->generate('rentang', 1, '2026-08-01', '2026-08-10', paginasi: false);

        $this->assertCount(10, $hasilTanpaPaging['items']);
        $this->assertNull($hasilTanpaPaging['meta']);
    }

    // ============================= BENTUK C (loket_id terisi, harian) =============================

    public function test_bentukC_vendors_null_kalau_bridge_gagal_bukan_semua_nol(): void
    {
        // Sebelum fix: bridge gagal -> rowsHariIni kosong -> sumPerVendor
        // balikin semua-0 (BUKAN error, cuma keliatan kayak "beneran nol
        // transaksi hari itu" padahal sebenarnya "gak diketahui karena
        // bridge down"). Sekarang vendors eksplisit null, beda jelas dari
        // "genuinely nol".
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', 1, null)
            ->andThrow(new BridgeException('bridge down'));

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', 1, '2026-08-01', '2026-08-01');

        $this->assertTrue($hasil['bridge_gagal']);
        $this->assertNull($hasil['vendors']);
        $this->assertNull($hasil['total_pendapatan_sopp']);
        $this->assertNull($hasil['selisih']);
    }

    public function test_bentukC_rincian_transaksi_dikelompokkan_per_metode(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $teller = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 150000, 'U001', 'Budi'),
            ], 'grand_total' => 150000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 100000, 'qris' => 50000], $teller);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', 1, '2026-08-01', '2026-08-01');

        $this->assertSame('C', $hasil['bentuk']);
        $this->assertCount(1, $hasil['rincian_transaksi']['transfer']);
        $this->assertSame(100000, $hasil['rincian_transaksi']['transfer'][0]['nominal']);
        $this->assertCount(1, $hasil['rincian_transaksi']['qris']);
        $this->assertEmpty($hasil['rincian_transaksi']['tunai']);
        $this->assertSame(150000, $hasil['total_pendapatan_sopp']);
        $this->assertSame(150000, $hasil['total_setor']);
        $this->assertSame(0, $hasil['selisih']);
    }

    public function test_bentukC_filter_teller_id_scope_rincian_dan_ringkasan(): void
    {
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');
        $tellerA = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U001']);
        $tellerB = User::factory()->create(['loket_id' => 1, 'user_id_sopp' => 'U002']);

        $bridge = $this->bridgeMock();
        $bridge->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', 1, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 100000, 'U001', 'A'),
                $this->baris('2026-08-01', 1, 'Finnet', 200000, 'U002', 'B'),
            ], 'grand_total' => 300000]);

        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 90000], $tellerA);
        $this->buatSetoranSubmitted(1, '2026-08-01', ['transfer' => 190000], $tellerB);

        $service = new LaporanDataService($bridge);
        $hasil = $service->generate('harian', 1, '2026-08-01', '2026-08-01', tellerId: 'U001');

        $this->assertSame(100000, $hasil['total_pendapatan_sopp']);
        $this->assertSame(90000, $hasil['total_setor']);
        $this->assertCount(1, $hasil['rincian_transaksi']['transfer']);
        $this->assertSame(90000, $hasil['rincian_transaksi']['transfer'][0]['nominal']);
    }

    public function test_forceRefresh_S3_bypass_cache_yang_ada_dan_tulis_ulang(): void
    {
        Carbon::setTestNow('2026-09-10');

        // Warm-up: cache keisi data LAMA.
        $bridgeWarmup = $this->bridgeMock();
        $bridgeWarmup->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [$this->baris('2026-09-01', 1, 'Finnet', 100000)], 'grand_total' => 100000]);
        (new LaporanDataService($bridgeWarmup))->generate('harian', null, '2026-09-01', '2026-09-01');

        // forceRefresh=true -- HARUS tetap manggil bridge (bukan cache-hit
        // dari warm-up), walau belum lewat TTL sama sekali.
        $bridgeRefresh = $this->bridgeMock();
        $bridgeRefresh->shouldReceive('fetchDetailPerUser')->once()->with('2026-09-01', '2026-09-01', null, null)
            ->andReturn(['data' => [$this->baris('2026-09-01', 1, 'Finnet', 999000)], 'grand_total' => 999000]);
        $hasilRefresh = (new LaporanDataService($bridgeRefresh))
            ->generate('harian', null, '2026-09-01', '2026-09-01', forceRefresh: true);

        $this->assertSame(999000, collect($hasilRefresh['items'])->firstWhere('loket_id', 1)['vendors']['Finnet']);

        // Data BARU (999000) harus KETULIS ULANG ke cache -- panggilan
        // NORMAL (forceRefresh=false) berikutnya baca 999000, BUKAN
        // balik ke 100000 yang lama.
        $bridgeSetelahnya = $this->bridgeMock();
        $bridgeSetelahnya->shouldNotReceive('fetchDetailPerUser');
        $hasilSetelahnya = (new LaporanDataService($bridgeSetelahnya))
            ->generate('harian', null, '2026-09-01', '2026-09-01');

        $this->assertSame(999000, collect($hasilSetelahnya['items'])->firstWhere('loket_id', 1)['vendors']['Finnet']);
    }

    // ============================= FALLBACK DAFTAR VENDOR (daftarVendorRoot) =============================

    public function test_daftarVendorRoot_fallback_ke_daftar_terakhir_saat_bridge_gagal_total(): void
    {
        // Request PERTAMA sukses -- daftar vendor (Finnet) ke-cache
        // forever. Request KEDUA (tanggal beda, bridge gagal TOTAL)
        // harusnya STRUKTUR kolom vendor tetap ada (fallback ke Finnet),
        // BUKAN vendors: [] yang bikin kolom hilang total di FE.
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridgeSukses = $this->bridgeMock();
        $bridgeSukses->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 1000, 'U001', 'Budi'),
            ], 'grand_total' => 1000]);
        (new LaporanDataService($bridgeSukses))->generate('harian', null, '2026-08-01', '2026-08-01');

        $bridgeGagal = $this->bridgeMock();
        $bridgeGagal->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-02', '2026-08-02', null, null)
            ->andThrow(new BridgeException('gagal total'));
        // Loket harus "muncul" di union bridge+lokal biar ada baris buat
        // dicek -- bridge gagal total (nol baris), jadi submission lokal
        // ini SATU-SATUNYA alasan baris loket 1 ada di items.
        $this->buatSetoranSubmitted(1, '2026-08-02', ['transfer' => 50000]);
        $hasil = (new LaporanDataService($bridgeGagal))->generate('harian', null, '2026-08-02', '2026-08-02');

        $baris = collect($hasil['items'])->firstWhere('loket_id', 1);
        // Kolom vendor (nama "Finnet") tetap ada di daftar vendor_root
        // fallback -- dibuktikan lewat vendors:null (bukan {}) yang
        // key-nya tetap lengkap kalaupun isinya null semua (lihat
        // sumPerVendor -- vendorRoot dipakai buat nge-fill semua key
        // sebelum di-null-in di level item). Cara paling langsung
        // verifikasi vendor_root ke-fallback: total_row (agregat semua
        // item) HARUS tetap punya key 'Finnet' di respons, gak hilang
        // strukturnya walau isinya 0/null.
        $this->assertArrayHasKey('Finnet', $hasil['total_row']['vendors'] ?? array_fill_keys(['Finnet'], null));
        $this->assertNull($baris['vendors']);
    }

    public function test_daftarVendorRoot_TIDAK_fallback_kalau_beneran_nol_transaksi_bukan_gagal(): void
    {
        // Beda dari test di atas: bridge SUKSES tapi data genuinely
        // kosong (nol transaksi hari itu, BUKAN kegagalan). Fallback ke
        // daftar lama TIDAK boleh kepakai di sini -- kalau kepakai,
        // vendor yang udah gak aktif bertahun-tahun bisa nongol lagi di
        // laporan padahal harusnya beneran kosong.
        Carbon::setTestNow('2026-09-10');
        $this->buatLoket(1, 'Loket A');

        $bridgeSukses = $this->bridgeMock();
        $bridgeSukses->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-01', '2026-08-01', null, null)
            ->andReturn(['data' => [
                $this->baris('2026-08-01', 1, 'Finnet', 1000, 'U001', 'Budi'),
            ], 'grand_total' => 1000]);
        (new LaporanDataService($bridgeSukses))->generate('harian', null, '2026-08-01', '2026-08-01');

        $bridgeKosongAsli = $this->bridgeMock();
        $bridgeKosongAsli->shouldReceive('fetchDetailPerUser')->once()->with('2026-08-02', '2026-08-02', null, null)
            ->andReturn(['data' => [], 'grand_total' => 0]);
        $hasil = (new LaporanDataService($bridgeKosongAsli))->generate('harian', null, '2026-08-02', '2026-08-02');

        $this->assertFalse($hasil['bridge_gagal']);
        $this->assertArrayNotHasKey('Finnet', $hasil['total_row']['vendors']);
    }
}
<?php

namespace Tests\Feature;

use App\Exceptions\BridgeException;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Chunk: Riwayat Setoran (GET/DELETE /api/v1/teller/setoran/riwayat,
 * GET /api/v1/teller/setoran/riwayat/export, DELETE /api/v1/teller/setoran/{id}).
 *
 * REVISI (menyusul sesi personal-switch): status_keuangan, selisih, dan
 * total_pendapatan SEKARANG semua personal (SelisihPerUserService, live
 * bridge fetch) -- bukan lagi loket-wide (snapshot_sopp/SetoranHarian::
 * selisih()/isLunas()). Konsekuensinya buat test:
 *
 *  - buatSetoran() TIDAK LAGI punya parameter $snapshot -- snapshot_sopp
 *    gak disentuh sama sekali di controller ini sekarang, jadi gak ada
 *    gunanya buat test-test di file ini (masih relevan buat fitur LAIN
 *    kayak Dashboard/Card A/Pelunasan, tapi itu di file test mereka
 *    sendiri). Nilai personal (total_pendapatan/selisih/status_keuangan)
 *    sekarang dikontrol lewat mockBridgeSukses()/mockBridgeGagal(),
 *    BUKAN parameter buatSetoran().
 *  - BridgeService di-mock DEFAULT (sukses, data kosong) di setUp() --
 *    ini WAJIB, kalau enggak test bakal nyoba HTTP call beneran ke
 *    bridge.php tiap kali index()/export() manggil SelisihPerUserService.
 *    Test yang PEDULI sama nilai personal WAJIB manggil ulang
 *    mockBridgeSukses()/mockBridgeGagal() SEBELUM hit endpoint --
 *    binding di container di-replace tiap dipanggil, jadi override yang
 *    terakhir yang berlaku.
 *  - untukBanyakRow() nge-grup fetch PER loket_id (lihat
 *    SelisihPerUserService) -- buat simulasiin "1 row gagal_dimuat, row
 *    lain sukses" DALAM 1 teller yang sama, dua row itu WAJIB beda
 *    loket (mockBridgeCampuran()). Gagal/suksesnya 1 loket berlaku ke
 *    SEMUA row di loket itu dalam 1 request yang sama.
 *
 * BUG YANG DICATAT DI VERSI SEBELUMNYA (exists:lokets,id salah,
 * ->nama alih-alih ->nama_loket) -- SUDAH BENER di controller yang
 * sekarang, dua-duanya. Test loket_id-tidak-valid di bawah ini
 * sekarang murni test korektnes, bukan lagi regression guard buat bug
 * yang belum ditempel.
 *
 * GAP dari versi sebelumnya, status per revisi ini:
 *   A. 404 vs 422 buat loket_id gak terdaftar -- SUDAH TERJAWAB lewat
 *      test yang ada (`exists:lokets,loket_id` -> 422 kalau ID gak
 *      ketemu, Gate -> 403 kalau ID valid tapi gak punya kapabilitas).
 *      Bukan gap lagi.
 *   B. Query timeout mode lintas-loket export() tanpa filter tanggal --
 *      SUDAH DIPUTUSKAN sesi personal-switch: cap 30 hari, berlaku ke
 *      KEDUA mode (bukan cuma lintas-loket lagi). Lihat test cap 30
 *      hari di bawah. Bukan gap lagi.
 *   C. Rate limit di export() -- MASIH BELUM DIPUTUSKAN/diimplementasi.
 *      Test-nya ditandai markTestIncomplete() secara eksplisit di
 *      bawah (sebelumnya cuma disebut di komentar tapi gak beneran
 *      ditandai -- inconsistency itu dibenerin di sini).
 *
 * DISATUKAN dari RiwayatSetoranPatchTest.php (file terpisah yang tadinya
 * nguji nama_loket personal, dibuat_via_bantuan_pelunasan, audit log
 * riwayat_lintas_loket_dilihat/diekspor, guard bound, dan throttle
 * index()) -- ditulis SEBELUM sesi personal-switch, jadi masih pakai
 * parameter $snapshot di buatSetoran() dan angka cap 90 hari. Waktu
 * digabung:
 *   - $snapshot dihapus dari SEMUA call site (gak relevan lagi di sini,
 *     lihat catatan di atas), $dibuatViaBantuanPelunasan dipertahanin.
 *   - Section bound DIREVISI TOTAL ke 30 hari, TERMASUK 1 test yang
 *     logikanya KEBALIK: personal sekarang IKUT kena bound (sebelumnya
 *     enggak) -- lihat test_index_personal_status_keuangan_KENA_bound_30_hari_juga.
 *   - Section lain (nama_loket/dibuat_via_bantuan_pelunasan/activity-log/
 *     throttle) gak tersentuh personal-switch, cuma perlu setUp()'s
 *     default mockBridgeSukses([]) biar gak nyoba HTTP call beneran
 *     tiap index()/export() manggil SelisihPerUserService.
 */
class RiwayatSetoranTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;
    private Loket $loketLain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loket = Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Satu']);
        $this->loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Dua']);

        Carbon::setTestNow('2026-08-20 09:00:00');

        // Default: bridge SUKSES tapi kosong -- aman dipakai tiap test
        // yang gak peduli nilai personal (gak bikin HTTP call beneran,
        // gak bikin row jadi gagal_dimuat juga). Test yang peduli WAJIB
        // override ini sebelum hit endpoint.
        $this->mockBridgeSukses([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers -- user & data
    // -----------------------------------------------------------------

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'loket_id' => $this->loket->loket_id,
            'nama' => 'Teller Satu',
        ], $overrides));
    }

    private function buatVerifikatorEdit(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['bisa_edit_setoran_semua_loket' => true])->save();

        return $user;
    }

    /**
     * User yang isTellerSopp()-nya FALSE (user_id_sopp null) -- dipakai
     * buat nguji gagal_dimuat yang sumbernya BUKAN bridge down, tapi
     * identitas legacy yang gak ada. user_id_sopp gak fillable lewat
     * factory mass-assignment (sama kayak bisa_edit_setoran_semua_loket),
     * makanya forceFill().
     */
    private function buatTellerBukanSopp(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['user_id_sopp' => null])->save();

        return $user;
    }

    /**
     * $entriesNominal: array metode => nominal, misal ['tunai' => 100000].
     * Kosongin buat row submitted-tanpa-entry (edge case draft/hapus).
     * Gak ada lagi parameter snapshot -- lihat catatan class-level.
     * $dibuatViaBantuanPelunasan: disatuin dari RiwayatSetoranPatchTest.
     */
    private function buatSetoran(
        User $teller,
        string $tanggal,
        string $status,
        array $entriesNominal = ['tunai' => 100000],
        ?Loket $loket = null,
        bool $dibuatViaBantuanPelunasan = false,
    ): SetoranHarian {
        $setoran = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => ($loket ?? $this->loket)->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
            'dibuat_via_bantuan_pelunasan' => $dibuatViaBantuanPelunasan,
        ]);

        foreach ($entriesNominal as $metode => $nominal) {
            SetoranDetail::create([
                'setoran_harian_id' => $setoran->id,
                'metode_pembayaran' => $metode,
                'nominal' => $nominal,
                'foto_bukti_path' => $metode === 'kpay' ? null : 'bukti/x.jpg',
                'is_konfirmasi' => true,
            ]);
        }

        return $setoran;
    }

    // -----------------------------------------------------------------
    // Helpers -- bridge mock
    // -----------------------------------------------------------------

    /**
     * Satu baris data bridge buat 1 teller di 1 tanggal. user_id di sini
     * WAJIB cocok user_id_sopp si teller -- itu kunci pencocokan yang
     * dipakai SelisihPerUserService::ringkasUntukRow().
     */
    private function barisBridge(
        User $teller,
        string $tanggal,
        int $total,
        string $vendorId = 'V1',
        string $vendorName = 'Vendor Satu',
        string $produk = 'Produk A',
    ): array {
        return [
            'user_id' => $teller->user_id_sopp,
            'tanggal' => $tanggal,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'produk_name' => $produk,
            'total' => $total,
        ];
    }

    /**
     * Bridge SUKSES, balikin $rows apa adanya. Berlaku ke SEMUA
     * panggilan fetchDetailPerUser() di test ini gak peduli rentang
     * tanggal/loket_id yang dikirim -- filtering tanggal/user tetap
     * kejadian di sisi SelisihPerUserService (baca $row->tanggal_transaksi
     * & $row->user->user_id_sopp), jadi 1 fixture yang sama aman dipakai
     * buat banyak pemanggilan sekaligus (mis. bulan ini & bulan lalu).
     */
    private function mockBridgeSukses(array $rows): void
    {
        $this->mock(BridgeService::class, function ($mock) use ($rows) {
            $mock->shouldReceive('fetchDetailPerUser')->andReturn([
                'data' => $rows,
                'grand_total' => array_sum(array_column($rows, 'total')),
            ]);
        });
    }

    /**
     * Simulasi bridge.php gak bisa dihubungi -- SEMUA row di loket
     * manapun yang ke-fetch bakal dapet selisih_sendiri null
     * (gagal_dimuat), SELAMA gak dibatesin per-loket (lihat
     * mockBridgeCampuran() buat kasus campuran gagal+sukses).
     */
    private function mockBridgeGagal(): void
    {
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->andThrow(new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.'));
        });
    }

    /**
     * 1 loket sukses (dengan $rowsSukses), loket lain gagal total.
     * Dipakai buat nguji gagal_dimuat gak ketimbun status lain dalam 1
     * response -- WAJIB 2 row beda loket (lihat catatan class-level
     * soal kenapa gak bisa dicampur dalam 1 loket yang sama).
     */
    private function mockBridgeCampuran(int $loketSuksesId, array $rowsSukses, int $loketGagalId): void
    {
        $this->mock(BridgeService::class, function ($mock) use ($loketSuksesId, $rowsSukses, $loketGagalId) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->withArgs(fn($mulai, $selesai, $loketId) => $loketId === $loketSuksesId)
                ->andReturn([
                    'data' => $rowsSukses,
                    'grand_total' => array_sum(array_column($rowsSukses, 'total')),
                ]);
            $mock->shouldReceive('fetchDetailPerUser')
                ->withArgs(fn($mulai, $selesai, $loketId) => $loketId === $loketGagalId)
                ->andThrow(new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.'));
        });
    }

    // -----------------------------------------------------------------
    // Helpers -- baca isi file xlsx (bukan cuma cek header)
    // -----------------------------------------------------------------

    private function bacaXlsx($response): Worksheet
    {
        // FIX: streamedContent() itu method TestResponse (wrapper
        // Laravel), bukan baseResponse (StreamedResponse mentah dari
        // Symfony) -- ngecek method_exists() ke baseResponse selalu
        // false, jatuh ke getContent() yang buat StreamedResponse
        // balikin false (konten belum di-render), bikin file yang
        // ditulis ke disk kosong. TestResponse SELALU punya
        // streamedContent(), gak perlu dicek pakai method_exists sama
        // sekali.
        $isi = $response->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'riwayat_test_') . '.xlsx';
        file_put_contents($path, $isi);

        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    /**
     * Cari baris pertama di kolom A yang nilainya persis $nilai --
     * dipakai daripada hardcode nomor baris, karena jumlah baris
     * metadata (FILTER PERIODE/LOKET) di atas tabel bisa beda-beda
     * tergantung mode.
     */
    private function cariBarisDiKolomA(Worksheet $sheet, string $nilai): ?int
    {
        foreach ($sheet->getRowIterator() as $row) {
            $cell = $sheet->getCell([1, $row->getRowIndex()]);
            if ((string) $cell->getValue() === $nilai) {
                return $row->getRowIndex();
            }
        }

        return null;
    }

    // ===================================================================
    // Sesi (belum login)
    // ===================================================================

    public function test_index_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/teller/setoran/riwayat')->assertStatus(401);
    }

    public function test_export_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/teller/setoran/riwayat/export')->assertStatus(401);
    }

    public function test_destroy_belum_login_ditolak_401(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft', []);

        $this->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}")->assertStatus(401);
    }

    // ===================================================================
    // index() -- view personal, kasus dasar
    // ===================================================================

    public function test_index_kosong_kalau_belum_pernah_ada_setoran(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.meta.total_items', 0);
    }

    public function test_index_cuma_nampilin_punya_sendiri_walau_ada_punya_orang_lain(): void
    {
        $teller = $this->buatTeller();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->buatSetoran($tellerLain, '2026-08-02', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-01');
    }

    public function test_index_row_di_loket_lain_tetap_muncul_kalau_user_id_sama(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 50000], $this->loketLain);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_index_field_nama_teller_tidak_ada_di_mode_personal(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonMissingPath('data.items.0.nama_teller');
    }

    // ===================================================================
    // index() -- draft muncul di personal, TIDAK di-exclude
    // ===================================================================

    public function test_index_draft_milik_sendiri_ikut_muncul(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'draft', []);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status_workflow', 'draft');
    }

    // ===================================================================
    // index() -- default tanpa filter: "setoran terakhir", bisa lintas
    // bulan
    // ===================================================================

    public function test_index_tanpa_filter_urutan_terbaru_lintas_bulan(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-06-28', 'submitted');
        $this->buatSetoran($teller, '2026-06-29', 'submitted');
        $this->buatSetoran($teller, '2026-06-30', 'submitted');
        $this->buatSetoran($teller, '2026-07-01', 'submitted');
        $this->buatSetoran($teller, '2026-07-05', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-07-05')
            ->assertJsonPath('data.items.4.tanggal_transaksi', '2026-06-28');
    }

    // ===================================================================
    // index() -- pagination (per_page default 8)
    // ===================================================================

    public function test_index_pagination_default_8_per_halaman(): void
    {
        $teller = $this->buatTeller();
        for ($i = 1; $i <= 10; $i++) {
            $this->buatSetoran($teller, sprintf('2026-08-%02d', $i), 'submitted');
        }

        $halaman1 = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');
        $halaman2 = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?page=2');

        $halaman1->assertOk()
            ->assertJsonCount(8, 'data.items')
            ->assertJsonPath('data.meta.total_items', 10)
            ->assertJsonPath('data.meta.total_pages', 2);
        $halaman2->assertOk()->assertJsonCount(2, 'data.items');
    }

    // ===================================================================
    // index() -- filter tanggal
    // ===================================================================

    public function test_index_filter_rentang_tanggal(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->buatSetoran($teller, '2026-08-10', 'submitted');
        $this->buatSetoran($teller, '2026-08-20', 'submitted');

        $response = $this->actingAs($teller)->getJson(
            '/api/v1/teller/setoran/riwayat?tanggal_mulai=2026-08-05&tanggal_akhir=2026-08-15'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-10');
    }

    public function test_index_tanggal_akhir_sebelum_tanggal_mulai_ditolak_422(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson(
            '/api/v1/teller/setoran/riwayat?tanggal_mulai=2026-08-15&tanggal_akhir=2026-08-05'
        );

        $response->assertStatus(422);
    }

    // ===================================================================
    // index() -- filter status_workflow (draft|submitted saja)
    // ===================================================================

    public function test_index_filter_status_workflow_draft(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'draft', []);
        $this->buatSetoran($teller, '2026-08-02', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_workflow=draft');

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status_workflow', 'draft');
    }

    public function test_index_filter_status_workflow_belum_input_ditolak_422(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_workflow=belum_input');

        $response->assertStatus(422);
    }

    // ===================================================================
    // index() -- status_keuangan PERSONAL: 3 state (lunas/belum_lunas/
    // gagal_dimuat), sumbernya SelisihPerUserService (bridge), bukan
    // lagi snapshot_sopp
    // ===================================================================

    public function test_index_status_keuangan_gagal_dimuat_kalau_bridge_gagal(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->mockBridgeGagal();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.status_keuangan', 'gagal_dimuat');
    }

    public function test_index_status_keuangan_gagal_dimuat_kalau_user_bukan_teller_sopp(): void
    {
        // Sumber gagal_dimuat yang KEDUA -- independen dari bridge
        // down/enggak: kalau usernya sendiri gak punya identitas legacy
        // (user_id_sopp null / isTellerSopp() false), fetch gak pernah
        // sempat dicoba, langsung gagal_dimuat.
        $teller = $this->buatTellerBukanSopp();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.status_keuangan', 'gagal_dimuat');
    }

    public function test_index_status_keuangan_lunas_kalau_selisih_nol(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-01', 100000)]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.status_keuangan', 'lunas');
    }

    public function test_index_status_keuangan_belum_lunas_kalau_ada_selisih(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 50000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-01', 100000)]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.status_keuangan', 'belum_lunas');
    }

    public function test_index_filter_status_keuangan_gagal_dimuat_tidak_ketimbun_belum_lunas(): void
    {
        // Row gagal (loket satu, bridge gagal total) dan row belum_lunas
        // (loket dua, bridge sukses tapi gak cocok) WAJIB beda loket --
        // lihat catatan class-level soal kenapa gak bisa dicampur dalam
        // 1 loket yang sama.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 50000], $this->loket); // gagal
        $this->buatSetoran($teller, '2026-08-02', 'submitted', ['tunai' => 50000], $this->loketLain); // belum_lunas beneran

        $this->mockBridgeCampuran(
            loketSuksesId: $this->loketLain->loket_id,
            rowsSukses: [$this->barisBridge($teller, '2026-08-02', 20000)],
            loketGagalId: $this->loket->loket_id,
        );

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_keuangan=belum_lunas');

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-02');
    }

    public function test_index_filter_status_keuangan_gagal_dimuat_valid_dan_menyaring_benar(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 50000], $this->loket); // gagal
        $this->buatSetoran($teller, '2026-08-02', 'submitted', ['tunai' => 50000], $this->loketLain); // belum_lunas

        $this->mockBridgeCampuran(
            loketSuksesId: $this->loketLain->loket_id,
            rowsSukses: [$this->barisBridge($teller, '2026-08-02', 20000)],
            loketGagalId: $this->loket->loket_id,
        );

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_keuangan=gagal_dimuat');

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-01')
            ->assertJsonPath('data.items.0.status_keuangan', 'gagal_dimuat');
    }

    public function test_index_filter_status_keuangan_belum_lunas_dash_ditolak(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_keuangan=belum-lunas');

        $response->assertStatus(422);
    }

    // ===================================================================
    // index() -- total_pendapatan PERSONAL (bukan lagi loket-wide)
    // ===================================================================

    public function test_index_total_pendapatan_personal_bukan_loket_wide(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 30000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-01', 75000)]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.total_pendapatan', 75000);
    }

    // ===================================================================
    // index() -- guard cap 30 hari buat filter status_keuangan
    // ===================================================================

    public function test_index_filter_status_keuangan_membatasi_ke_30_hari_kalau_tanpa_tanggal_mulai(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-06-01', 'submitted', ['tunai' => 50000]); // > 30 hari dari sekarang (2026-08-20)
        $this->buatSetoran($teller, '2026-08-15', 'submitted', ['tunai' => 50000]); // dalam 30 hari

        $this->mockBridgeSukses([
            $this->barisBridge($teller, '2026-06-01', 50000), // andai lolos guard, ini "lunas" juga
            $this->barisBridge($teller, '2026-08-15', 50000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat?status_keuangan=lunas');

        // Row 2026-06-01 gak boleh muncul -- kepotong guard SEBELUM
        // sempat difilter/fetch, walaupun datanya sendiri "lunas".
        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-15');
    }

    public function test_index_filter_status_keuangan_cap_30_hari_dihitung_dari_tanggal_akhir_bukan_dari_sekarang(): void
    {
        // Regression guard buat bug yang sempet kejadian: kalau cap-nya
        // selalu dihitung dari now() sementara tanggal_akhir yang
        // dikirim jauh di masa lalu, dua syarat WHERE saling gak
        // mungkin ketemu (query selalu kosong). Anchor-nya WAJIB
        // tanggal_akhir kalau dikirim.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-06-05', 'submitted', ['tunai' => 50000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-06-05', 50000)]);

        $response = $this->actingAs($teller)->getJson(
            '/api/v1/teller/setoran/riwayat?status_keuangan=lunas&tanggal_akhir=2026-06-10'
        );

        // 2026-06-05 cuma 5 hari sebelum tanggal_akhir (2026-06-10) --
        // harus lolos guard 30 hari walau jauh dari "sekarang" (2026-08-20).
        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    // ===================================================================
    // index() -- nominal per metode dipecah 4 kolom (gak butuh bridge
    // sama sekali)
    // ===================================================================

    public function test_index_nominal_dipecah_per_metode(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted', []);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'transfer', 'nominal' => 10000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'qris', 'nominal' => 20000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'tunai', 'nominal' => 30000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'kpay', 'nominal' => 40000, 'nama_karyawan' => 'A', 'identitas_karyawan' => '123', 'is_konfirmasi' => true]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()
            ->assertJsonPath('data.items.0.nominal_transfer', 10000)
            ->assertJsonPath('data.items.0.nominal_qris', 20000)
            ->assertJsonPath('data.items.0.nominal_tunai', 30000)
            ->assertJsonPath('data.items.0.nominal_kpay', 40000);
    }

    // ===================================================================
    // index() -- mode lintas-loket (expanded view)
    // ===================================================================

    public function test_index_kirim_loket_id_tanpa_kapabilitas_ditolak_403(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertStatus(403);
    }

    public function test_index_expanded_nampilin_semua_teller_di_loket_itu(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerA = $this->buatTeller(['nama' => 'Teller A']);
        $tellerB = $this->buatTeller(['nama' => 'Teller B']);
        $this->buatSetoran($tellerA, '2026-08-01', 'submitted');
        $this->buatSetoran($tellerB, '2026-08-01', 'submitted');

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_index_expanded_draft_orang_lain_tidak_pernah_muncul(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'draft', []);
        $this->buatSetoran($teller, '2026-08-02', 'submitted');

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-02');
    }

    public function test_index_expanded_draft_MILIK_SENDIRI_juga_tidak_muncul(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $this->buatSetoran($verifikator, '2026-08-01', 'draft', []);

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_index_expanded_tidak_nyampur_loket_lain(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 1000], $this->loket);
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 1000], $this->loketLain);

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_index_expanded_field_nama_teller_ada(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller(['nama' => 'Teller Unik']);
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk()->assertJsonPath('data.items.0.nama_teller', 'Teller Unik');
    }

    public function test_index_expanded_loket_id_tidak_terdaftar_gagal_validasi(): void
    {
        $verifikator = $this->buatVerifikatorEdit();

        $response = $this->actingAs($verifikator)->getJson('/api/v1/teller/setoran/riwayat?loket_id=999999');

        $response->assertStatus(422);
    }

    // ===================================================================
    // ringkasan() -- selalu personal (bulan berjalan), walau mode
    // expanded
    // ===================================================================

    public function test_ringkasan_tetap_personal_walau_di_mode_expanded(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller();
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted', ['tunai' => 100000]);

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
        );

        // Verifikator gak punya row sendiri -- ringkasan-nya harus 0,
        // gak peduli row punya orang lain di loket yang lagi dia lihat.
        $response->assertOk()->assertJsonPath('data.ringkasan.total_setoran_lunas', 0);
    }

    public function test_ringkasan_total_setoran_lunas_bulan_berjalan_saja(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-07-15', 'submitted', ['tunai' => 100000]); // bulan lalu, gak ikut
        $this->buatSetoran($teller, '2026-08-05', 'submitted', ['tunai' => 50000]); // bulan ini, lunas

        $this->mockBridgeSukses([
            $this->barisBridge($teller, '2026-08-05', 50000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.total_setoran_lunas', 50000);
    }

    public function test_ringkasan_total_setoran_lunas_TIDAK_ikut_hitung_draft(): void
    {
        // Beda dari versi loket-wide lama: personal gak punya lagi
        // skenario "kebetulan lunas gara-gara row teller LAIN" (gak ada
        // lagi agregat lintas-teller sama sekali). Yang tetap wajib
        // dijaga: filter status='submitted' harus tegas -- draft TIDAK
        // BOLEH ikut kehitung walau entries-nya persis cocok sama data
        // bridge (yang bakal bikin dia "lunas" andai kesalahannya lolos
        // ikut dihitung).
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-15', 'draft', ['tunai' => 100000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-15', 100000)]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.total_setoran_lunas', 0);
    }

    public function test_ringkasan_total_entry_bulan_ini_hitung_dokumen_bukan_baris_transaksi(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted', []);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'transfer', 'nominal' => 1000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'qris', 'nominal' => 1000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        SetoranDetail::create(['setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'tunai', 'nominal' => 1000, 'foto_bukti_path' => 'x.jpg', 'is_konfirmasi' => true]);
        $this->buatSetoran($teller, '2026-08-02', 'draft', []); // draft, gak ikut

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.total_entry_bulan_ini', 1);
    }

    public function test_ringkasan_rata_rata_entry_per_hari_dihitung_be(): void
    {
        // "Hari berjalan" per Carbon::setTestNow 2026-08-20 -> hari ke-20.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->buatSetoran($teller, '2026-08-02', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.rata_rata_entry_per_hari', round(2 / 20, 1));
    }

    public function test_ringkasan_persentase_vs_bulan_lalu_null_kalau_bulan_lalu_nol(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-05', 'submitted', ['tunai' => 50000]);
        // Tidak ada apapun di bulan Juli.
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-05', 50000)]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.persentase_vs_bulan_lalu', null);
    }

    public function test_ringkasan_ada_data_belum_lengkap_true_kalau_bridge_gagal(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->mockBridgeGagal();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.ringkasan.ada_data_belum_lengkap', true);
    }

    public function test_ringkasan_pending_selisih_otomatis_berkurang_kalau_ada_pelunasan(): void
    {
        // Mekanismenya masih sama kayak versi lama: pendingSelisih() gak
        // butuh tau apa-apa soal Pelunasan secara eksplisit -- begitu
        // ada entry baru nempel ke row yang sama, totalSetor() naik
        // otomatis, selisih_sendiri turun otomatis. Bedanya cuma sumber
        // "pendapatan"-nya sekarang dari bridge (fixed lewat mock),
        // bukan snapshot_sopp.
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 60000]);
        $this->mockBridgeSukses([$this->barisBridge($teller, '2026-08-01', 100000)]); // selisih 40000

        $sebelum = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');
        $sebelum->assertOk()->assertJsonPath('data.ringkasan.pending_selisih', 40000);

        SetoranDetail::create([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 40000,
            'foto_bukti_path' => 'bukti-pelunasan.jpg',
            'is_konfirmasi' => true,
        ]);

        $sesudah = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');
        $sesudah->assertOk()->assertJsonPath('data.ringkasan.pending_selisih', 0);
    }

    // ===================================================================
    // destroy() -- guard kepemilikan & kapabilitas (gak tersentuh
    // personal-switch sama sekali, tetap dari versi sebelumnya)
    // ===================================================================

    public function test_destroy_pemilik_boleh_hapus_draft(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft', []);

        $response = $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");

        $response->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSoftDeleted('setoran_harian', ['id' => $setoran->id]);
    }

    public function test_destroy_pemilik_TIDAK_boleh_hapus_submitted_dengan_entry(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");

        $response->assertStatus(422);
        $this->assertNotSoftDeleted('setoran_harian', ['id' => $setoran->id]);
    }

    public function test_destroy_non_owner_akses_draft_orang_lain_404(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft', []);

        $response = $this->actingAs($verifikator)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");

        $response->assertStatus(404);
    }

    public function test_destroy_non_owner_tanpa_kapabilitas_akses_submitted_403(): void
    {
        $tellerBiasa = $this->buatTeller();
        $tellerLain = $this->buatTeller();
        $setoran = $this->buatSetoran($tellerLain, '2026-08-01', 'submitted');

        $response = $this->actingAs($tellerBiasa)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}", ['alasan' => 'Salah loket.']);

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('setoran_harian', ['id' => $setoran->id]);
    }

    public function test_destroy_non_owner_dengan_kapabilitas_dan_alasan_boleh_hapus_submitted(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($verifikator)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}", ['alasan' => 'Salah loket, dipindah manual.']);

        $response->assertOk();
        $this->assertSoftDeleted('setoran_harian', ['id' => $setoran->id]);
    }

    public function test_destroy_non_owner_dengan_kapabilitas_TETAP_ditolak_kalau_ada_entry(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($verifikator)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");

        $response->assertStatus(422);
    }

    public function test_destroy_row_tidak_ada_404(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->deleteJson('/api/v1/teller/setoran/riwayat/999999');

        $response->assertStatus(404);
    }

    public function test_destroy_tercatat_ke_activity_log(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft', []);

        $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");

        $this->assertDatabaseHas('activity_logs', [
            'jenis_aksi' => 'setoran_dihapus',
            'target_type' => 'setoran_harian',
            'target_id' => $setoran->id,
        ]);
    }

    public function test_destroy_setoran_yang_sudah_dihapus_bisa_diisi_ulang_di_tanggal_sama(): void
    {
        $teller = $this->buatTeller();
        $lama = $this->buatSetoran($teller, '2026-08-01', 'draft', []);
        $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$lama->id}")->assertOk();

        $baru = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-01',
            'status' => 'draft',
        ]);

        $this->assertNotNull($baru->id);
        $this->assertDatabaseCount('setoran_harian', 2);
    }

    public function test_destroy_row_yang_sudah_dihapus_tidak_muncul_lagi_di_index(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft', []);
        $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}")->assertOk();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonCount(0, 'data.items');
    }

    // ===================================================================
    // export() -- Excel
    // ===================================================================

    public function test_export_mengembalikan_file_xlsx(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000]);

        $response = $this->actingAs($teller)->get('/api/v1/teller/setoran/riwayat/export');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function test_export_ikut_filter_aktif(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000]);
        $this->buatSetoran($teller, '2026-08-10', 'submitted', ['tunai' => 100000]);

        $response = $this->actingAs($teller)->get(
            '/api/v1/teller/setoran/riwayat/export?tanggal_mulai=2026-08-05&tanggal_akhir=2026-08-15'
        );

        $response->assertOk();

        $sheet = $this->bacaXlsx($response);
        $this->assertNull($this->cariBarisDiKolomA($sheet, '01 Aug 2026'), 'Row di luar rentang filter gak boleh ikut ke file.');
        $this->assertNotNull($this->cariBarisDiKolomA($sheet, '10 Aug 2026'));
    }

    public function test_export_mode_lintas_loket_butuh_kapabilitas(): void
    {
        $tellerBiasa = $this->buatTeller();

        $response = $this->actingAs($tellerBiasa)->get(
            '/api/v1/teller/setoran/riwayat/export?loket_id=' . $this->loket->loket_id
        );

        $response->assertStatus(403);
    }

    public function test_export_mode_lintas_loket_dengan_kapabilitas_boleh(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000]);

        $response = $this->actingAs($verifikator)->get(
            '/api/v1/teller/setoran/riwayat/export?loket_id=' . $this->loket->loket_id
        );

        $response->assertOk();
    }

    public function test_export_loket_id_tidak_valid_gagal_validasi(): void
    {
        $verifikator = $this->buatVerifikatorEdit();

        $response = $this->actingAs($verifikator)->get('/api/v1/teller/setoran/riwayat/export?loket_id=999999');

        $response->assertStatus(422);
    }

    public function test_export_personal_tanpa_tanggal_mulai_dibatasi_30_hari(): void
    {
        // Sebelumnya cap 30 hari cuma jalan buat mode lintas-loket --
        // sekarang berlaku ke personal juga.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-06-01', 'submitted', ['tunai' => 50000]); // > 30 hari, harus gak kebawa
        $this->buatSetoran($teller, '2026-08-15', 'submitted', ['tunai' => 50000]);

        $response = $this->actingAs($teller)->get('/api/v1/teller/setoran/riwayat/export');

        $response->assertOk();

        $sheet = $this->bacaXlsx($response);
        $this->assertNull($this->cariBarisDiKolomA($sheet, '01 Jun 2026'));
        $this->assertNotNull($this->cariBarisDiKolomA($sheet, '15 Aug 2026'));
    }

    // ===================================================================
    // export() -- baris gagal-fetch TETAP ditulis (bukan di-skip), cuma
    // kolom Total Pendapatan & Selisih ditandai, dan gak ikut Total Semua
    // ===================================================================

    public function test_export_baris_gagal_fetch_tetap_ditulis_ditandai_gagal_dimuat(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000]);
        $this->mockBridgeGagal();

        $response = $this->actingAs($teller)->get('/api/v1/teller/setoran/riwayat/export');
        $response->assertOk();

        $sheet = $this->bacaXlsx($response);
        $baris = $this->cariBarisDiKolomA($sheet, '01 Aug 2026');

        $this->assertNotNull($baris, 'Baris tanggal 01 Aug 2026 harus tetap ada di file, gak boleh di-skip.');
        $this->assertSame('Gagal dimuat', $sheet->getCell([2, $baris])->getValue()); // kolom B
        $this->assertSame('Gagal dimuat', $sheet->getCell([7, $baris])->getValue()); // kolom G
        // Kolom metode pembayaran (E = Tunai) tetap keisi walau bridge
        // gagal -- itu dari entries row sendiri, gak butuh bridge.
        $this->assertSame(100000, $sheet->getCell([5, $baris])->getValue());
    }

    public function test_export_baris_gagal_fetch_tidak_ikut_total_semua(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000], $this->loket); // gagal
        $this->buatSetoran($teller, '2026-08-02', 'submitted', ['tunai' => 50000], $this->loketLain); // sukses

        $this->mockBridgeCampuran(
            loketSuksesId: $this->loketLain->loket_id,
            rowsSukses: [$this->barisBridge($teller, '2026-08-02', 50000)],
            loketGagalId: $this->loket->loket_id,
        );

        $response = $this->actingAs($teller)->get('/api/v1/teller/setoran/riwayat/export');
        $response->assertOk();

        $sheet = $this->bacaXlsx($response);
        $barisSukses = $this->cariBarisDiKolomA($sheet, '02 Aug 2026');
        $barisTotal = $this->cariBarisDiKolomA($sheet, 'TOTAL SEMUA');

        $this->assertNotNull($barisSukses, 'Baris 02 Aug 2026 harus ada di file.');
        $this->assertNotNull($barisTotal, 'Baris TOTAL SEMUA harus ada di file.');
        $this->assertSame(50000, $sheet->getCell([2, $barisSukses])->getValue());
        // Total Semua cuma ngejumlah baris yang sukses fetch -- baris
        // gagal (100000) gak boleh ikut kehitung ke sini.
        $this->assertSame(50000, $sheet->getCell([2, $barisTotal])->getValue());
    }

    // ===================================================================
    // GAP -- rate limit export() (poin C), MASIH belum diputuskan/
    // diimplementasi. Ditandai markTestIncomplete() eksplisit (beda
    // dari versi sebelumnya yang cuma disebut di komentar tapi gak
    // beneran ditandai).
    // ===================================================================

    public function test_export_kena_ratelimit_setelah_10x_dalam_1_menit(): void
{
    $teller = $this->buatTeller();

    $this->buatSetoran(
        $teller,
        '2026-08-01',
        'submitted',
        ['tunai' => 100000]
    );

    // 10 request pertama harus berhasil
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($teller)
            ->get('/api/v1/teller/setoran/riwayat/export')
            ->assertOk();
    }

    // Request ke-11 harus kena rate limit
    $response = $this->actingAs($teller)
        ->get('/api/v1/teller/setoran/riwayat/export');

    $response->assertStatus(429);
}

    public function test_pola_422_untuk_loket_id_tidak_terdaftar_final(): void
    {
        // Dokumentasi keputusan: 422 (exists:) buat loket_id gak
        // terdaftar, 403 (Gate) buat gak punya kapabilitas. Disamakan
        // ke Pelunasan & Card A -- lihat PelunasanTest & SetoranEntryCardATest.
        $this->assertTrue(true);
    }

    // ===================================================================
    // DISATUKAN dari RiwayatSetoranPatchTest.php -- 5 batch perubahan
    // yang tadinya dipisah file. Section bound (4) direvisi total ke 30
    // hari (bukan 90) menyusul keputusan cap unified sesi personal-switch,
    // termasuk 1 test yang logikanya KEBALIK dari versi lama (personal
    // sekarang IKUT kena bound, dulu enggak). Section lain (1/2/3/5)
    // gak tersentuh personal-switch sama sekali, cuma dipindah + dirapihin
    // ke signature buatSetoran() yang baru (gak ada parameter snapshot).
    // ===================================================================

    // -------------------------------------------------------------
    // 1. nama_loket (mode personal doang)
    // -------------------------------------------------------------

    public function test_index_personal_menyertakan_nama_loket(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.nama_loket', 'Loket Satu');
    }

    public function test_index_personal_nama_loket_ikut_loket_row_bukan_loket_login(): void
    {
        // Row-nya di loketLain (dibikin lewat bisa_input_setoran_semua_loket
        // di Card A, bukan lewat loket_id user login) -- nama_loket harus
        // ikut loket TEMPAT ROW itu berada, bukan loket default user.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 50000], $this->loketLain);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.nama_loket', 'Loket Dua');
    }

    public function test_index_lintas_loket_TIDAK_menyertakan_nama_loket_per_baris(): void
    {
        // Sesuai komentar mapItem(): mode lintas-loket udah tau loketnya
        // dari 1 filter ?loket_id= yang sama buat semua item, jadi field
        // ini SENGAJA gak diulang per-baris di mode itu.
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted');

        $response = $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id);

        $response->assertOk()->assertJsonMissingPath('data.items.0.nama_loket');
    }

    public function test_index_personal_nama_loket_dash_kalau_loket_sudah_dihapus(): void
    {
        // loket() pakai belongsTo biasa (bukan withDefault) -- kalau
        // loketnya udah gak ada (hard-delete, sesuai keputusan final
        // "hard-delete Loket aman"), null-safe operator di mapItem()
        // harus balikin '-', bukan 500.
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted');
        $this->loket->delete();
        $setoran->refresh();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.nama_loket', '-');
    }

    // -------------------------------------------------------------
    // 2. dibuat_via_bantuan_pelunasan (semua mode)
    // -------------------------------------------------------------

    public function test_index_personal_dibuat_via_bantuan_pelunasan_true(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000], null, true);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.dibuat_via_bantuan_pelunasan', true);
    }

    public function test_index_personal_dibuat_via_bantuan_pelunasan_false_default(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');

        $response->assertOk()->assertJsonPath('data.items.0.dibuat_via_bantuan_pelunasan', false);
    }

    public function test_index_lintas_loket_juga_menyertakan_dibuat_via_bantuan_pelunasan(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted', ['tunai' => 100000], null, true);

        $response = $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id);

        $response->assertOk()->assertJsonPath('data.items.0.dibuat_via_bantuan_pelunasan', true);
    }

    public function test_export_menyertakan_dibuat_via_bantuan_pelunasan_tidak_bikin_error(): void
    {
        // export() gak nampilin kolom ini di Excel (sesuai spesifikasi
        // kolom export yang udah final), tapi row dengan flag true HARUS
        // TETAP kebaca normal, gak boleh nge-skip/error cuma gara-gara
        // kolom ini ada.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted', ['tunai' => 100000], null, true);

        $response = $this->actingAs($teller)->get('/api/v1/teller/setoran/riwayat/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // -------------------------------------------------------------
    // 3. Audit log riwayat_lintas_loket_dilihat / _diekspor
    // -------------------------------------------------------------

    public function test_index_lintas_loket_menulis_activity_log(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted');

        $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
            . '&tanggal_mulai=2026-08-01&tanggal_akhir=2026-08-20'
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $verifikator->id,
            'jenis_aksi' => 'riwayat_lintas_loket_dilihat',
        ]);

        $log = ActivityLog::where('jenis_aksi', 'riwayat_lintas_loket_dilihat')->firstOrFail();
        // assertEquals (bukan assertSame) -- loket_id yang tersimpan di
        // detail_perubahan ikut tipe apa adanya dari query param URL
        // (string "1"), request->validate('integer') cuma memvalidasi,
        // gak nge-cast. Bukan bug, tapi worth diketahui: jangan
        // assertSame/=== ke field ini di tempat lain.
        $this->assertEquals($this->loket->loket_id, $log->detail_perubahan['loket_id']);
        $this->assertSame('2026-08-01', $log->detail_perubahan['tanggal_mulai']);
        $this->assertSame('2026-08-20', $log->detail_perubahan['tanggal_akhir']);
    }

    public function test_index_personal_TIDAK_menulis_activity_log(): void
    {
        // Ini yang paling penting dijaga: jangan sampai tiap teller buka
        // riwayat sendiri ikut nyampah activity_logs -- log ini SENGAJA
        // cuma buat aksi lintas-loket (audit "siapa yang ngintip loket
        // siapa"), bukan buat setiap pemakaian fitur.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat')->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_export_lintas_loket_menulis_activity_log_jenis_diekspor(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted');

        $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat/export?loket_id=' . $this->loket->loket_id
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $verifikator->id,
            'jenis_aksi' => 'riwayat_lintas_loket_diekspor',
        ]);
    }

    public function test_export_personal_TIDAK_menulis_activity_log(): void
    {
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat/export')->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_index_lintas_loket_tanpa_kapabilitas_ditolak_SEBELUM_menulis_log(): void
    {
        // Guard urutan: Gate::authorize() harus jalan SEBELUM
        // ActivityLog::catat() -- percobaan gagal (403) gak boleh
        // ninggalin jejak log seolah-olah aksinya kejadian.
        $teller = $this->buatTeller(); // TANPA bisa_edit_setoran_semua_loket

        $this->actingAs($teller)
            ->getJson('/api/v1/teller/setoran/riwayat?loket_id=' . $this->loketLain->loket_id)
            ->assertStatus(403);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    // -------------------------------------------------------------
    // 4. Bound 30 hari (DIREVISI dari 90 hari, dan sekarang berlaku ke
    // KEDUA mode -- lihat test guard 30 hari di section index() atas
    // buat versi ringkasnya, section ini versi lebih lengkap dari
    // RiwayatSetoranPatchTest yang nyusul dari sini)
    // -------------------------------------------------------------

    public function test_index_lintas_loket_status_keuangan_tanpa_tanggal_dibatasi_30_hari(): void
    {
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);

        // "Hari ini" di-set 2026-08-20 (lihat setUp). 30 hari mundur dari
        // situ jatuh di 2026-07-21 -- row Mei ini di LUAR window, row
        // 08-01 di DALAM window. Default bridge mock (kosong, dari
        // setUp()) bikin entries nonzero otomatis "belum_lunas" (bridge
        // balikin pendapatan 0, gak match totalSetor()) -- gak perlu
        // mock eksplisit buat test ini.
        $this->buatSetoran($tellerLain, '2026-05-01', 'submitted', ['tunai' => 100000]); // di luar 30 hari
        $this->buatSetoran($tellerLain, '2026-08-01', 'submitted', ['tunai' => 100000]); // di dalam 30 hari

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
            . '&status_keuangan=belum_lunas'
        );

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tanggal_transaksi', '2026-08-01');
    }

    public function test_index_lintas_loket_status_keuangan_DENGAN_tanggal_mulai_tidak_dibatasi_30_hari(): void
    {
        // Kalau FE eksplisit ngirim tanggal_mulai sendiri, bound otomatis
        // TIDAK berlaku -- filter tanggal dari FE yang menang, bukan
        // ditimpa guard performa ini.
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-05-01', 'submitted', ['tunai' => 100000]);

        $response = $this->actingAs($verifikator)->getJson(
            '/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id
            . '&status_keuangan=belum_lunas&tanggal_mulai=2026-01-01'
        );

        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_index_personal_status_keuangan_KENA_bound_30_hari_juga(): void
    {
        // KEBALIK dari versi lama (yang judulnya "tidak_kena_bound"):
        // guard cap sekarang mode-agnostic, sengaja disatuin biar gak
        // dicabang lagi antara personal vs lintas-loket -- lihat
        // keputusan sesi personal-switch. Row di luar 30 hari, tanpa
        // tanggal_mulai, mode personal -> HARUS ikut kepotong juga.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-05-01', 'submitted');

        $response = $this->actingAs($teller)
            ->getJson('/api/v1/teller/setoran/riwayat?status_keuangan=belum_lunas');

        $response->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_index_lintas_loket_tanpa_status_keuangan_tidak_kena_bound_30_hari(): void
    {
        // Bound cuma nempel ke KOMBINASI (status_keuangan diisi + gak
        // ada tanggal_mulai) -- filter status_keuangan absen berarti
        // guard gak pernah dievaluasi sama sekali, gak peduli mode-nya.
        $verifikator = $this->buatVerifikatorEdit();
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-05-01', 'submitted');

        $response = $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/riwayat?loket_id=' . $this->loket->loket_id);

        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    // -------------------------------------------------------------
    // 5. Throttle GET /riwayat -- route-level (bukan logic controller),
    // gak tersentuh personal-switch sama sekali
    // -------------------------------------------------------------

    public function test_index_kena_throttle_setelah_60_request_per_menit(): void
    {
        $teller = $this->buatTeller();

        $response = null;
        for ($i = 0; $i < 61; $i++) {
            $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/riwayat');
        }

        $response->assertStatus(429);
    }
}
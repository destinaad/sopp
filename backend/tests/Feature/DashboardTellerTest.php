<?php

namespace Tests\Feature;

use App\Exceptions\BridgeException;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk: Dashboard Teller (GET /api/v1/teller/dashboard).
 * Depend ke shared logic #3 (perhitungan finansial), #4 (bridge & snapshot),
 * #7 (activity log -- read tidak ada di sini, halaman ini murni baca setoran_harian),
 * #9 (sesi terautentikasi). TIDAK depend ke #1/#2/#5/#6/#8, jadi tidak perlu
 * setup kapabilitas/otorisasi/upload/rate-limit/gate halaman di sini.
 *
 * SnapshotService di-mock di setiap test (bukan BridgeService langsung) --
 * controller memanggil snapshotService->getForLoketTanggal() dua kali per
 * request (hari ini & kemarin), masing-masing dibungkus try/catch
 * BridgeException independen (§6.2), jadi setiap kombinasi
 * sukses/gagal x sukses/gagal wajib ditest terpisah.
 *
 * =====================================================================
 * PERUBAHAN dari audit shared logic #3 (versi file ini vs sebelumnya):
 *
 *   1. selisih() sekarang GABUNGAN semua teller di loket+tanggal yang
 *      sama, HANYA yang statusnya submitted -- draft (termasuk milik
 *      teller yang sedang login sendiri) TIDAK ikut dihitung. Section
 *      baru "Multi-teller & submitted-only" di bawah nge-lock perilaku
 *      ini secara eksplisit, termasuk kasus yang paling gampang salah
 *      asumsi: draft milik SENDIRI juga tidak ikut.
 *   2. status_keuangan di riwayat_terakhir() komples pokoknya bs ada tampilan user lain, 
 * intinya yang ada di dashboard beneran loket
 *   3. Field baru pendapatan_kemarin_status, supaya FE bisa bedain
 *      "kemarin gagal diambil" vs "kemarin memang tidak ada
 *      pendapatan" -- section baru di bawah nge-cover kombinasi
 *      gagal/sukses x gagal/sukses.
 *   4. test_selisih_dan_total_setor_dihitung_dari_entries_sungguhan
 *      (nama lama) diubah statusnya dari 'draft' jadi 'submitted' --
 *      dengan aturan submitted-only yang baru, draft tidak lagi ikut
 *      dihitung, jadi test yang sebelumnya sengaja pakai draft untuk
 *      menguji "entries sungguhan ikut kehitung" sekarang HARUS pakai
 *      submitted, kalau tidak testnya sendiri jadi tidak valid lagi.
 * =====================================================================
 */
class DashboardTellerTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;

    protected function setUp(): void
    {
        parent::setUp();

        // Loket bukan auto-increment (primary key loket_id manual, lihat model),
        // jadi dibuat eksplisit, bukan lewat factory (Loket tidak punya HasFactory).
        $this->loket = Loket::create([
            'loket_id' => 1,
            'nama_loket' => 'Loket Satu',
        ]);

        Carbon::setTestNow('2026-08-07 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper: bind mock SnapshotService yang membedakan respons per
     * tanggal yang diminta (hari-ini vs kemarin dipanggil terpisah
     * oleh controller, dengan tanggal berbeda).
     *
     * $byDate: ['2026-08-07' => [...array snapshot...] | 'throw', ...]
     * Tanggal yang tidak ada di array dianggap tidak akan pernah diminta
     * di test itu (kalau ternyata diminta, Mockery akan mengembalikan null
     * lewat andReturnUsing, bukan gagal keras -- supaya error lebih mudah dibaca
     * lewat assertion response, bukan lewat exception Mockery yang membingungkan).
     */
    private function mockSnapshot(array $byDate): void
    {
        $this->mock(SnapshotService::class, function ($mock) use ($byDate) {
            $mock->shouldReceive('getForLoketTanggal')
                ->andReturnUsing(function ($loketId, $tanggal, $forceRefresh, $user) use ($byDate) {
                    $result = $byDate[$tanggal] ?? null;

                    if ($result === 'throw') {
                        throw new BridgeException('bridge tidak bisa dihubungi');
                    }

                    return $result;
                });

            // Default: kosong. Test yang beneran mau nguji isi
            // aktivitas_terakhir pakai mockAktivitasTerakhir() di
            // bawah buat override ini secara spesifik.
            $mock->shouldReceive('aktivitasTerakhirUntukLoket')
                ->andReturn([]);
        });
    }

    /**
     * Override default [] dari mockSnapshot() -- pakai ini SETELAH
     * mockSnapshot() kalau test butuh isi aktivitas_terakhir yang
     * spesifik. Gak nguji ulang logic DI DALAM SnapshotService (itu
     * domain SnapshotServiceTest sendiri) -- ini cuma mastiin
     * controller nerusin apa yang dibalikin SnapshotService ke
     * response tanpa diutak-atik.
     */
    private function mockAktivitasTerakhir(array $aktivitas): void
    {
        $this->mock(SnapshotService::class, function ($mock) use ($aktivitas) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn($this->snapshotSukses(0));
            $mock->shouldReceive('aktivitasTerakhirUntukLoket')->andReturn($aktivitas);
        });
    }

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'loket_id' => $this->loket->loket_id,
            'nama' => 'Teller Satu',
        ], $overrides));
    }

    private function snapshotSukses(int $grandTotal, string $fetchedAt = '2026-08-07T08:55:00+07:00', bool $dataTerkini = true): array
    {
        return ['grand_total' => $grandTotal, 'fetched_at' => $fetchedAt, 'data_terkini' => $dataTerkini];
    }

    /**
     * Helper: bikin row setoran_harian + 1 entry (kalau nominal > 0)
     * untuk teller & tanggal manapun. Dipakai buat skenario multi-teller
     * (beda dari buatRiwayat() di bawah yang selalu pakai snapshot_sopp
     * sendiri per row -- helper ini TIDAK isi snapshot_sopp, karena
     * dipakai buat row "hari ini" yang datanya dari mock bridge live,
     * bukan dari kolom snapshot_sopp tersimpan).
     */
    private function buatSetoran(User $teller, string $tanggal, string $status, int $nominal, ?Loket $loket = null): SetoranHarian
    {
        $setoran = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => ($loket ?? $this->loket)->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);

        if ($nominal > 0) {
            SetoranDetail::create([
                'setoran_harian_id' => $setoran->id,
                'metode_pembayaran' => 'tunai',
                'nominal' => $nominal,
                'foto_bukti_path' => 'bukti_setoran/x.jpg',
                'is_konfirmasi' => true,
            ]);
        }

        return $setoran;
    }

    // -----------------------------------------------------------------
    // Sesi (#9)
    // -----------------------------------------------------------------

    public function test_belum_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/teller/dashboard');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Kasus dasar: belum ada setoran hari ini sama sekali
    // -----------------------------------------------------------------

    public function test_belum_ada_setoran_hari_ini_status_belum_input(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.status_setoran', 'belum_input')
            ->assertJsonPath('data.pendapatan_sopp', 500000)
            ->assertJsonPath('data.selisih', 500000) // 500000 - totalSetorUntukLoketTanggal(0)
            ->assertJsonPath('data.ada_selisih', true)
            ->assertJsonPath('data.nama_teller', 'Teller Satu')
            ->assertJsonPath('data.nama_loket', 'Loket Satu')
            ->assertJsonPath('data.tanggal_transaksi', '2026-08-07')
            ->assertJsonPath('data.pendapatan_sopp_status', null);
    }

    // -----------------------------------------------------------------
    // Status setoran mengikuti kolom `status` row, bukan ditebak (#3)
    // -----------------------------------------------------------------

    public function test_status_setoran_draft_kalau_row_ada_tapi_belum_disubmit(): void
    {
        $teller = $this->buatTeller();
        SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-07',
            'status' => 'draft',
        ]);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonPath('data.status_setoran', 'draft');
    }

    public function test_status_setoran_submitted_kalau_sudah_disubmit(): void
    {
        $teller = $this->buatTeller();
        SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-07',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonPath('data.status_setoran', 'submitted');
    }

    // -----------------------------------------------------------------
    // Perhitungan selisih dari entries sungguhan (#3) -- termasuk aturan
    // is_konfirmasi tidak boleh memengaruhi selisih
    // -----------------------------------------------------------------

    public function test_selisih_dihitung_dari_entries_sungguhan_saat_submitted(): void
    {
        $teller = $this->buatTeller();
        // PENTING: status HARUS submitted -- dengan aturan submitted-only
        // yang baru, kalau status ini draft, entries di bawah TIDAK akan
        // ikut kehitung di selisih sama sekali (lihat section "Multi-teller
        // & submitted-only" di bawah untuk test yang mengunci perilaku itu).
        $setoran = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-07',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        SetoranDetail::create([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 200000,
            'foto_bukti_path' => 'bukti_setoran/a.jpg',
            'is_konfirmasi' => true,
        ]);
        SetoranDetail::create([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'kpay',
            'nominal' => 100000,
            'is_konfirmasi' => false, // sengaja belum dikonfirmasi
            'nama_karyawan' => 'Budi',
            'identitas_karyawan' => '12345',
        ]);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // totalSetor = 200000 + 100000 = 300000, walau salah satu belum
        // dikonfirmasi -- is_konfirmasi cuma sinyal tampilan, bukan penentu total.
        $response->assertOk()
            ->assertJsonPath('data.selisih', 200000) // 500000 - 300000
            ->assertJsonPath('data.ada_selisih', true);
    }

    public function test_ada_selisih_false_kalau_selisih_persis_nol(): void
    {
        $teller = $this->buatTeller();
        $setoran = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-07',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        SetoranDetail::create([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'tunai',
            'nominal' => 500000,
            'foto_bukti_path' => 'bukti_setoran/a.jpg',
            'is_konfirmasi' => true,
        ]);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.selisih', 0)
            ->assertJsonPath('data.ada_selisih', false);
    }

    // -----------------------------------------------------------------
    // Multi-teller & submitted-only (FIX shared logic #3) -- inti dari
    // audit sesi ini. Bridge/laporan sistem lama (dikonfirmasi ke
    // sopp_report_date_loket.php: GROUP BY trans_loket_id doang, tanpa
    // trans_user_id) tidak punya konsep pemisahan per-petugas, jadi
    // selisih WAJIB dibandingkan ke total gabungan loket, bukan 1 user.
    // Dan gabungan itu HANYA menghitung yang sudah submitted -- draft
    // (progress yang masih bisa berubah/dihapus kapan saja) tidak boleh
    // dianggap "uang yang sudah resmi masuk", termasuk draft milik
    // teller yang sedang login sendiri.
    // -----------------------------------------------------------------

    public function test_selisih_menggabungkan_submitted_dari_semua_teller_di_loket_yang_sama(): void
    {
        $tellerA = $this->buatTeller(['nama' => 'Teller A']);
        $tellerB = $this->buatTeller(['nama' => 'Teller B']);
        $this->buatSetoran($tellerA, '2026-08-07', 'submitted', 200000);
        $this->buatSetoran($tellerB, '2026-08-07', 'submitted', 150000);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        // Dilihat dari sudut pandang Teller A -- selisih yang dia lihat
        // HARUS ikut menghitung setoran submitted Teller B juga.
        $response = $this->actingAs($tellerA)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonPath('data.selisih', 150000); // 500000 - (200000 + 150000)
    }

    public function test_draft_milik_teller_lain_tidak_ikut_dihitung_di_selisih(): void
    {
        $tellerA = $this->buatTeller(['nama' => 'Teller A']);
        $tellerB = $this->buatTeller(['nama' => 'Teller B']);
        // Teller A belum input apa-apa sama sekali hari ini.
        $this->buatSetoran($tellerB, '2026-08-07', 'draft', 300000); // draft, bukan submitted
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($tellerA)->getJson('/api/v1/teller/dashboard');

        // Draft Teller B TIDAK ikut kehitung -- selisih tetap penuh
        // seolah belum ada setoran sama sekali di loket ini hari ini.
        $response->assertOk()
            ->assertJsonPath('data.selisih', 500000)
            ->assertJsonPath('data.status_setoran', 'belum_input'); // isolasi status tetap terjaga
    }

    public function test_draft_milik_sendiri_juga_tidak_ikut_dihitung_di_selisih(): void
    {
        // Kasus paling gampang salah asumsi: teller aktif ngisi form
        // Card A hari ini (draft, belum pencet submit). Keputusan bisnis
        // yang sudah dikonfirmasi: draft TETAP tidak boleh bikin dashboard
        // kelihatan "sudah setor" -- termasuk punya diri sendiri, bukan
        // cuma punya teller lain.
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, '2026-08-07', 'draft', 300000);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.selisih', 500000) // draft sendiri TIDAK mengurangi selisih
            ->assertJsonPath('data.ada_selisih', true)
            // status_setoran tetap 'draft' -- ini beda axis dari selisih
            // (kepemilikan/status form vs angka keuangan resmi), dua-duanya
            // boleh "tidak sinkron" secara sengaja.
            ->assertJsonPath('data.status_setoran', 'draft');
    }

    public function test_submitted_teller_lain_ikut_dihitung_walau_teller_sendiri_belum_input(): void
    {
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        $this->buatSetoran($tellerLain, '2026-08-07', 'submitted', 500000);

        $teller = $this->buatTeller(); // teller ini sendiri belum ada row
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // Loket sudah "lunas" berkat setoran teller lain, walau teller
        // yang sedang login belum pernah input apapun hari ini --
        // status_setoran tetap murni personal (belum_input), tapi
        // selisih ikut merefleksikan kondisi loket yang sebenarnya.
        $response->assertOk()
            ->assertJsonPath('data.status_setoran', 'belum_input')
            ->assertJsonPath('data.selisih', 0)
            ->assertJsonPath('data.ada_selisih', false);
    }

    public function test_setoran_loket_lain_tidak_ikut_kehitung_di_selisih(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Dua']);
        $tellerLoketLain = User::factory()->create(['loket_id' => $loketLain->loket_id, 'nama' => 'Teller Loket Lain']);
        $this->buatSetoran($tellerLoketLain, '2026-08-07', 'submitted', 999999, $loketLain);

        $teller = $this->buatTeller(); // loket 1
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // Loket agregasi WAJIB scoped per loket_id juga, bukan cuma
        // per tanggal -- kalau tidak, setoran loket lain bisa "bocor"
        // ke perhitungan selisih loket ini.
        $response->assertOk()->assertJsonPath('data.selisih', 500000);
    }

    // -----------------------------------------------------------------
    // Ketahanan bridge (#4) -- dua fetch independen, satu gagal tidak
    // boleh menjatuhkan yang lain / bikin 500
    // -----------------------------------------------------------------

    public function test_snapshot_hari_ini_gagal_tidak_membuat_500_dan_menandai_gagal_dimuat(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => 'throw',
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.pendapatan_sopp', null)
            ->assertJsonPath('data.pendapatan_sopp_status', 'gagal_dimuat')
            ->assertJsonPath('data.data_terkini', null)
            // Kemarin sukses di test ini -- pastikan status kemarin TIDAK
            // ikut ke-set 'gagal_dimuat' cuma karena hari ini gagal (dua
            // fetch itu independen, statusnya juga harus independen).
            ->assertJsonPath('data.pendapatan_kemarin_status', null)
            ->assertJsonPath('data.selisih', null)
            ->assertJsonPath('data.ada_selisih', null)
            ->assertJsonPath('data.fetched_at', null)
            // status_setoran tetap bisa dihitung walau bridge mati,
            // karena sumbernya lokal (DB), bukan bridge.
            ->assertJsonPath('data.status_setoran', 'belum_input');
    }

    public function test_snapshot_kemarin_gagal_tidak_menjatuhkan_data_hari_ini(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => 'throw',
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.pendapatan_sopp', 500000)
            ->assertJsonPath('data.pendapatan_sopp_status', null)
            ->assertJsonPath('data.pendapatan_kemarin_status', 'gagal_dimuat')
            ->assertJsonPath('data.persentase_vs_kemarin', null);
    }

    public function test_snapshot_hari_ini_dan_kemarin_dua_duanya_gagal(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => 'throw',
            '2026-08-06' => 'throw',
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // Tetap 200, bukan 500 -- prinsip inti ketahanan bridge.
        $response->assertOk()
            ->assertJsonPath('data.pendapatan_sopp_status', 'gagal_dimuat')
            ->assertJsonPath('data.pendapatan_kemarin_status', 'gagal_dimuat')
            ->assertJsonPath('data.persentase_vs_kemarin', null);
    }

    // -----------------------------------------------------------------
    // Persentase vs kemarin (#3) -- termasuk guard divide-by-zero, dan
    // field pendapatan_kemarin_status yang membedakan "kemarin gagal"
    // vs "kemarin memang tidak ada pendapatan" (FIX baru)
    // -----------------------------------------------------------------

    public function test_persentase_vs_kemarin_dihitung_benar_saat_naik(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(550000),
            '2026-08-06' => $this->snapshotSukses(500000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // (550000 - 500000) / 500000 * 100 = 10.0 -- dibandingkan lewat
        // assertEquals (bukan assertJsonPath yang strict ===), karena
        // json_encode(10.0) di PHP menghasilkan "10" (buang .0 di angka
        // bulat), sehingga hasil decode JSON jadi int 10, bukan float 10.0.
        $response->assertOk();
        $this->assertEquals(10.0, $response->json('data.persentase_vs_kemarin'));
    }

    public function test_persentase_vs_kemarin_dibulatkan_satu_desimal(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(333333),
            '2026-08-06' => $this->snapshotSukses(300000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // (333333 - 300000) / 300000 * 100 = 11.111 -> dibulatkan 11.1
        $response->assertOk()->assertJsonPath('data.persentase_vs_kemarin', 11.1);
    }

    public function test_persentase_vs_kemarin_null_kalau_kemarin_nol_guard_divide_by_zero(): void
    {
        $teller = $this->buatTeller();
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(0),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // Kemarin BERHASIL diambil (bukan gagal), nilainya cuma kebetulan
        // 0 -- pendapatan_kemarin_status HARUS tetap null di sini, supaya
        // FE bisa bedain dari kasus "kemarin gagal diambil" (test di atas).
        // Kalau status ini ikut 'gagal_dimuat' padahal datanya berhasil
        // diambil (cuma nilainya 0), itu justru bug baru yang sama
        // persis semangatnya dengan yang lagi kita benerin.
        $response->assertOk()
            ->assertJsonPath('data.persentase_vs_kemarin', null)
            ->assertJsonPath('data.pendapatan_kemarin_status', null);
    }

    // -----------------------------------------------------------------
    // nama_loket -- optional chaining kalau loket_id orphan (#3, defensif)
    // -----------------------------------------------------------------

    public function test_nama_loket_null_bukan_500_kalau_loket_tidak_ditemukan(): void
    {
        // loket_id menunjuk ke loket yang tidak ada di tabel lokets lokal
        // (skenario realistis: loket baru di SOPP lama, belum sempat sync-loket).
        $teller = $this->buatTeller(['loket_id' => 999]);
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonPath('data.nama_loket', null);
    }

    // -----------------------------------------------------------------
    // Defensif -- user tanpa loket_id (superadmin generik, user_id_sopp
    // & loket_id dua-duanya nullable di skema). Endpoint ini TIDAK
    // punya gate kapabilitas/halaman (#8) sama sekali, jadi backend
    // murni asumsi pemanggil sudah punya loket_id valid.
    // -----------------------------------------------------------------

    /**
     * Gap defensif yang belum ada keputusan resmi. SnapshotService::
     * getForLoketTanggal() men-type-hint parameter pertama sebagai
     * `int $loketId` (non-nullable). Kalau $user->loket_id null
     * (superadmin generik yang nyasar hit endpoint teller ini),
     * PHP melempar TypeError SEBELUM baris try/catch BridgeException
     * manapun sempat jalan -- TypeError bukan turunan BridgeException,
     * jadi tidak ikut tertangkap oleh catch yang sudah ada, dan
     * berpotensi jatuh jadi 500 mentah, bertentangan dengan prinsip
     * ketahanan yang sudah disepakati di bagian lain endpoint ini.
     *
     * Test ini mengunci PERSYARATAN MINIMAL (respons eksplisit,
     * bukan 500 mentah) -- bukan mengklaim endpoint ini sudah benar
     * hari ini. Kalau test ini gagal, itu temuan nyata yang perlu
     * keputusan desain: gate akses halaman (tolak superadmin sebelum
     * masuk sini) ATAU guard null eksplisit di controller sebelum
     * memanggil SnapshotService.
     */
    public function test_user_tanpa_loket_id_tidak_boleh_500_mentah(): void
    {
        $superadmin = User::factory()->create([
            'loket_id' => null,
            'user_id_sopp' => null,
            'nama' => 'Superadmin',
        ]);
        // is_superadmin di luar $fillable secara sengaja (§3.4) --
        // di-set manual, bukan lewat mass assignment.
        $superadmin->forceFill(['is_superadmin' => true])->save();

        $response = $this->actingAs($superadmin)->getJson('/api/v1/teller/dashboard');

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Isolasi antar teller/loket -- setoran hari ini WAJIB scoped
    // user_id + loket_id, bukan cuma salah satunya
    // -----------------------------------------------------------------

    public function test_tidak_melihat_setoran_teller_lain_walau_loket_sama(): void
    {
        $tellerLain = $this->buatTeller(['nama' => 'Teller Lain']);
        SetoranHarian::create([
            'user_id' => $tellerLain->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-07',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $teller = $this->buatTeller(); // teller ini sendiri belum ada row
        $this->mockSnapshot([
            '2026-08-07' => $this->snapshotSukses(500000),
            '2026-08-06' => $this->snapshotSukses(400000),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        // Walau ada row submitted di loket yang sama (milik teller lain),
        // teller ini harus tetap lihat belum_input untuk dirinya sendiri.
        // (Row teller lain di sini tanpa entries, jadi tidak menambah
        // angka apapun -- fokus test ini murni isolasi status_setoran.
        // Untuk isolasi status_setoran DIGABUNG dengan angka selisih yang
        // benar-benar ikut kehitung, lihat
        // test_submitted_teller_lain_ikut_dihitung_walau_teller_sendiri_belum_input
        // di section "Multi-teller & submitted-only" di atas.)
        $response->assertOk()->assertJsonPath('data.status_setoran', 'belum_input');
    }

    public function test_aktivitas_terakhir_diterusin_apa_adanya_dari_snapshot_service(): void
    {
        $teller = $this->buatTeller();
        $this->mockAktivitasTerakhir([
            [
                'tanggal_transaksi' => '2026-08-06',
                'nama_teller' => 'Teller Lain',
                'total_pendapatan' => 400000,
                'total_setor' => 400000,
                'selisih' => 0,
                'status_keuangan' => 'lunas',
                'status_workflow' => 'submitted',
            ],
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonCount(1, 'data.aktivitas_terakhir')
            ->assertJsonPath('data.aktivitas_terakhir.0.tanggal_transaksi', '2026-08-06')
            ->assertJsonPath('data.aktivitas_terakhir.0.nama_teller', 'Teller Lain')
            ->assertJsonPath('data.aktivitas_terakhir.0.status_workflow', 'submitted');
    }

    public function test_aktivitas_terakhir_kosong_kalau_snapshot_service_balikin_kosong(): void
    {
        $teller = $this->buatTeller();
        $this->mockAktivitasTerakhir([]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

        $response->assertOk()->assertJsonCount(0, 'data.aktivitas_terakhir');
    }

    public function test_snapshot_hari_ini_fallback_basi_menandai_data_terkini_false(): void
{
    $teller = $this->buatTeller();
    $this->mockSnapshot([
        '2026-08-07' => $this->snapshotSukses(400000, dataTerkini: false),
        '2026-08-06' => $this->snapshotSukses(400000),
    ]);

    $response = $this->actingAs($teller)->getJson('/api/v1/teller/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.pendapatan_sopp', 400000)
        ->assertJsonPath('data.pendapatan_sopp_status', null)
        ->assertJsonPath('data.data_terkini', false);
}
}

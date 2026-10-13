<?php

namespace Tests\Feature;

use App\Exceptions\BridgeException;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\User;
use App\Services\BridgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/admin/laporan -- lihat LaporanDataServiceTest.php buat
 * logic Bentuk A/B/C secara detail (cache, N+1, drill-down, dst). File
 * ini fokus ke lapisan HTTP: auth, otorisasi, validasi filter, dan
 * pengkabelan controller -> trait -> service -> bridge (via mock) yang
 * benar end-to-end -- bukan mengulang semua skenario unit test.
 */
class LaporanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['nama' => 'Teller Satu'], $overrides));
    }

    private function buatAdminLaporan(array $overrides = []): User
    {
        $user = $this->buatTeller(array_merge(['nama' => 'Admin Laporan'], $overrides));
        $user->forceFill(['bisa_akses_laporan' => true])->save();

        return $user;
    }

    private function buatSuperadmin(array $overrides = []): User
    {
        $user = $this->buatTeller(array_merge(['nama' => 'Super Admin'], $overrides));
        $user->forceFill(['is_superadmin' => true])->save();

        return $user;
    }

    private function mockBridgeKosong(): void
    {
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetch')->andReturn(['data' => [], 'grand_total' => 0]);
            $mock->shouldReceive('fetchDetailPerUser')->andReturn(['data' => [], 'grand_total' => 0]);
        });
    }

    // ===================================================================
    // Auth & otorisasi
    // ===================================================================

    public function test_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01')
            ->assertStatus(401);
    }

    public function test_ditolak_403_kalau_bukan_admin_laporan_dan_bukan_superadmin(): void
    {
        $teller = $this->buatTeller();
        $this->mockBridgeKosong();

        $this->actingAs($teller)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01')
            ->assertStatus(403);
    }

    public function test_boleh_diakses_superadmin_walau_bisa_akses_laporan_false(): void
    {
        $superadmin = $this->buatSuperadmin(['bisa_akses_laporan' => false]);
        $this->mockBridgeKosong();

        $this->actingAs($superadmin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01')
            ->assertOk();
    }

    public function test_boleh_diakses_user_bisa_akses_laporan_walau_bukan_superadmin(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01')
            ->assertOk();
    }

    // ===================================================================
    // Validasi filter
    // ===================================================================

    public function test_mode_tidak_valid_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=mingguan&tanggal_mulai=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_mode_bulanan_tanpa_bulan_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=bulanan')
            ->assertStatus(422)
            ->assertJsonValidationErrors('bulan');
    }

    public function test_mode_bulanan_format_bulan_tidak_valid_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=bulanan&bulan=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('bulan');
    }

    public function test_mode_bulanan_pakai_param_bulan_diterjemahkan_ke_awal_akhir_bulan(): void
    {
        // FE kirim nilai mentah dropdown bulan (YYYY-MM) -- DIKONFIRMASI
        // langsung ke FE, bukan tanggal_mulai/tanggal_akhir kayak
        // mode=rentang. Backend yang nerjemahkan ke rentang tanggal
        // kalender penuh.
        //
        // SENGAJA pakai mock dengan `->with(...)` ketat (bukan
        // mockBridgeKosong() yang nerima argumen APAPUN) -- tanpa ini,
        // test cuma membuktikan responsnya 200, BUKAN membuktikan
        // tanggal yang beneran diteruskan ke bridge itu benar. Kalau
        // logic terjemahan salah (misal balik ke bulan lain), test
        // longgar tetap PASS PALSU karena mock nerima tanggal apapun.
        $admin = $this->buatAdminLaporan();
        Carbon::setTestNow('2026-05-15');

        $mock = \Mockery::mock(BridgeService::class);
        $mock->shouldReceive('fetch')->once()->with('2026-02-01', '2026-02-28', null, \Mockery::any())
            ->andReturn(['data' => [], 'grand_total' => 0]);
        $this->app->instance(BridgeService::class, $mock);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=bulanan&bulan=2026-02')
            ->assertOk();
    }

    public function test_mode_bulanan_bug_rollover_hari_ini_tanggal_31_tidak_menggeser_bulan_target(): void
    {
        // REGRESI: Carbon::createFromFormat('Y-m', $bulan) TANPA paksa
        // day=1 di string-nya (misal ->startOfMonth() dipanggil SETELAH
        // parse, bukan disertakan di format string) itu rollover bug --
        // hari-di-bulan ikut ke-fill dari HARI INI (bukan selalu 1), dan
        // kalau hari ini tanggal 31 sementara bulan target cuma 28/30
        // hari, hasilnya diam-diam geser ke BULAN LAIN sama sekali
        // (Februari 31 -> Maret 3, lalu startOfMonth() jadi 1 Maret,
        // BUKAN 1 Februari). Dites ketat pakai `->with(...)` biar
        // ketauan kalau regresi lagi -- bukan cuma assertOk.
        $admin = $this->buatAdminLaporan();
        Carbon::setTestNow('2026-01-31'); // hari ini tanggal 31

        $mock = \Mockery::mock(BridgeService::class);
        $mock->shouldReceive('fetch')->once()->with('2026-02-01', '2026-02-28', null, \Mockery::any())
            ->andReturn(['data' => [], 'grand_total' => 0]);
        $this->app->instance(BridgeService::class, $mock);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=bulanan&bulan=2026-02')
            ->assertOk();
    }

    public function test_mode_bulanan_tanggal_mulai_manual_diabaikan_gak_dipakai(): void
    {
        // mode=bulanan TIDAK menerima tanggal_mulai/tanggal_akhir sama
        // sekali -- cuma `bulan`. Kalau FE kebetulan tetap kirim
        // tanggal_mulai manual, field itu di-exclude dari validasi
        // (bukan dipakai diam-diam menggantikan `bulan`).
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=bulanan&bulan=2026-02&tanggal_mulai=1999-01-01')
            ->assertOk();
    }

    public function test_teller_id_tanpa_loket_id_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01&teller_id=U001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('teller_id');
    }

    public function test_teller_id_dengan_loket_id_diterima(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&loket_id=1&tanggal=2026-09-01&teller_id=U001')
            ->assertOk();
    }

    public function test_loket_id_tidak_ada_di_db_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&loket_id=999&tanggal=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('loket_id');
    }

    public function test_rentang_lebih_dari_366_hari_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-01-01&tanggal_akhir=2027-01-05')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_akhir');
    }

    public function test_rentang_persis_366_hari_diterima(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-01-01&tanggal_akhir=2027-01-01')
            ->assertOk();
    }

    public function test_mode_harian_mengabaikan_tanggal_akhir_yang_dikirim_FE(): void
    {
        $admin = $this->buatAdminLaporan();

        // FE (salah atau iseng) ngirim tanggal_akhir beda dari
        // tanggal_mulai walau mode=harian -- controller HARUS motong ini
        // jadi tanggal_mulai doang sebelum sampai ke service, bukan
        // meneruskan rentang 5 hari yang gak seharusnya ada di mode
        // harian.
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->withArgs(fn ($mulai, $selesai, $loketId, $actor) => $mulai === '2026-09-01' && $selesai === '2026-09-01')
                ->once()
                ->andReturn(['data' => [], 'grand_total' => 0]);
        });

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01&tanggal_akhir=2026-09-05')
            ->assertOk();
    }

    // ===================================================================
    // Pengkabelan end-to-end & activity log
    // ===================================================================

    public function test_response_shape_bentuk_A_dan_status_ok(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);

        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetch')->once()->andReturn([
                'data' => [[
                    'tanggal' => '2026-09-01', 'loket_id' => 1, 'vendor_id' => 1,
                    'vendor_name' => 'Finnet', 'total' => 500000,
                ]],
                'grand_total' => 500000,
            ]);
        });

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'data' => [
                    'bentuk' => 'A',
                    'bridge_gagal' => false,
                ],
            ])
            ->assertJsonPath('data.items.0.loket_id', 1)
            ->assertJsonPath('data.items.0.vendors.Finnet', 500000);
    }

    public function test_bridge_gagal_tetap_200_dengan_flag_bridge_gagal_true(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->andThrow(new BridgeException('gagal'));
        });

        // Kegagalan bridge itu KEADAAN, bukan error HTTP -- laporan tetap
        // harus bisa dibuka (meski datanya ditandai gagal_dimuat), bukan
        // dilempar jadi 500/503.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.bridge_gagal', true);
    }

    public function test_page_param_diteruskan_ke_service(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&loket_id=1&tanggal_mulai=2026-08-01&tanggal_akhir=2026-08-20&page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 2);
    }

    public function test_page_tidak_dikirim_default_ke_1(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&loket_id=1&tanggal_mulai=2026-08-01&tanggal_akhir=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 1);
    }

    public function test_menulis_activity_log_laporan_dilihat_dengan_parameter_filter(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&loket_id=1&tanggal=2026-09-01&teller_id=U001')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'jenis_aksi' => 'laporan_dilihat',
        ]);

        $log = ActivityLog::where('jenis_aksi', 'laporan_dilihat')->first();
        $detail = $log->detail_perubahan;
        $this->assertSame('harian', $detail['mode']);
        $this->assertSame(1, $detail['loket_id']);
        $this->assertSame('U001', $detail['teller_id']);
    }

    // ===================================================================
    // Patch: tanggal_akhir mode=harian dikecualikan dari validasi
    // ===================================================================

    public function test_mode_harian_tanggal_akhir_lebih_awal_dari_mulai_TETAP_diterima(): void
    {
        // tanggal_akhir di sini gak masuk akal (lebih awal dari
        // tanggal_mulai, harusnya gagal after_or_equal) -- tapi karena
        // mode=harian, nilainya dibuang TOTAL sebelum divalidasi/dipakai.
        // Sebelum patch ini, request kayak gini ditolak 422 padahal
        // nilainya emang gak pernah dipakai.
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-05&tanggal_akhir=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.bentuk', 'A'); // loket_id kosong -> selalu Bentuk A, terlepas dari mode
    }

    public function test_mode_harian_tanggal_akhir_format_tidak_valid_TETAP_diterima(): void
    {
        // Bukan cuma "lebih awal" -- format yang gak masuk regex Y-m-d
        // sama sekali pun harus tetap lolos untuk mode=harian, karena
        // field-nya dikecualikan dari validasi sebelum date_format
        // sempat dicek.
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-05&tanggal_akhir=bukan-tanggal')
            ->assertOk()
            ->assertJsonPath('data.bentuk', 'A'); // loket_id kosong -> selalu Bentuk A, terlepas dari mode
    }

    public function test_mode_rentang_tanggal_akhir_tidak_valid_TETAP_ditolak_422(): void
    {
        // Pengecualian di atas HARUS spesifik ke mode=harian doang --
        // mode lain yang beneran butuh tanggal_akhir tetap wajib
        // divalidasi seperti biasa (regresi guard buat excludeIf-nya).
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-09-05&tanggal_akhir=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_akhir');
    }

    // ===================================================================
    // Patch: rate-limit refresh sekarang termasuk mode di key
    // ===================================================================

    public function test_refresh_mode_harian_tidak_ikut_menghabiskan_budget_mode_rentang_tanggal_sama(): void
    {
        // Sebelum patch: key rate-limit gak termasuk mode, jadi refresh
        // mode=harian tanggal X dan refresh mode=rentang
        // tanggal_mulai=tanggal_akhir=X (loket sama-sama kosong)
        // berbagi 1 budget walau keduanya menyentuh cache namespace
        // yang beda (laporan_detail_semua_loket vs laporan_fetch).
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-05&refresh=true')
            ->assertOk();

        // Kalau key masih gabung (bug lama), request kedua ini bakal
        // 429 walau secara logic ini query yang SEPENUHNYA beda.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-09-05&tanggal_akhir=2026-09-05&refresh=true')
            ->assertOk();
    }

    public function test_refresh_mode_sama_tanggal_sama_KEDUA_kali_tetap_kena_429(): void
    {
        // Guard supaya patch di atas gak bablas menghilangkan throttle
        // itu sendiri -- 2 kali refresh BERUNTUN dengan mode+tanggal
        // yang PERSIS sama tetap harus kena limit seperti sebelumnya.
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-05&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=harian&tanggal=2026-09-05&refresh=true')
            ->assertStatus(429);
    }
}
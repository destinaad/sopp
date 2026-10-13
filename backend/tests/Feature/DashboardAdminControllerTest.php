<?php

namespace Tests\Feature;

use App\Exceptions\BridgeException;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk: Dashboard Admin (GET /api/v1/admin/dashboard).
 *
 * Sengaja MOCK BridgeService (bukan LaporanDataService) -- controller ini
 * REUSE LANGSUNG dua method LaporanDataService yang sudah dibikin public
 * (ambilDetailSemuaLoketHarian() & drillDownTeller()), jadi LaporanDataService
 * dipakai APA ADANYA (real object, cache real) di sini. Ini juga sekalian
 * membuktikan jaminan "cache key sama persis dengan Laporan mode=harian" --
 * lihat test_cache_dipakai_bareng_laporan_mode_harian_bridge_cuma_dipanggil_sekali
 * di bagian bawah.
 *
 * Referensi fakta yang dites di sini: dokumen
 * fakta-dashboard-admin.md (hasil pembacaan kode DashboardAdminController.php
 * + LaporanDataService.php + ThrottlesBridgeRefresh.php + migration index).
 */
class DashboardAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ===================================================================
    // Helper
    // ===================================================================

    private function buatLoket(int $id, string $nama = 'Loket Satu'): Loket
    {
        return Loket::create(['loket_id' => $id, 'nama_loket' => $nama]);
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

    private function buatEditorSemuaLoket(array $overrides = []): User
    {
        $user = $this->buatTeller(array_merge(['nama' => 'Editor Semua Loket'], $overrides));
        $user->forceFill(['bisa_edit_setoran_semua_loket' => true])->save();

        return $user;
    }

    /**
     * Bikin row setoran_harian (+ 1 entry SetoranDetail kalau $nominal > 0,
     * status_workflow-nya SELALU 'submitted' krn cuma baris submitted yang
     * ikut dihitung total_setor). Dipakai buat baris draft (nominal
     * biasanya 0/diabaikan) maupun submitted.
     */
    private function buatSetoran(User $teller, int $loketId, string $tanggal, string $status, int $nominal = 0): SetoranHarian
    {
        $setoran = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $loketId,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);

        if ($nominal > 0) {
            SetoranDetail::create([
                'setoran_harian_id' => $setoran->id,
                'metode_pembayaran' => 'tunai',
                'nominal' => $nominal,
                'is_konfirmasi' => true,
            ]);
        }

        return $setoran;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  baris bridge mentah -- minimal
     *                                             loket_id, user_id, user_name, total
     */
    private function mockBridge(array $rows): void
    {
        $grandTotal = array_sum(array_column($rows, 'total'));

        $this->mock(BridgeService::class, function ($mock) use ($rows, $grandTotal) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->andReturn(['data' => $rows, 'grand_total' => $grandTotal]);
        });
    }

    private function mockBridgeKosong(): void
    {
        $this->mockBridge([]);
    }

    private function mockBridgeGagalTotal(): void
    {
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->andThrow(new BridgeException('Tidak bisa menghubungi server SOPP lama saat ini.'));
        });
    }

    private function barisBridge(int $loketId, ?string $userId, ?string $userName, int $total): array
    {
        return [
            'loket_id' => $loketId,
            'user_id' => $userId,
            'user_name' => $userName,
            'vendor_id' => 1,
            'vendor_name' => 'Finnet',
            'total' => $total,
            'tanggal' => Carbon::now()->toDateString(),
        ];
    }

    // ===================================================================
    // Auth & otorisasi
    // ===================================================================

    public function test_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    public function test_teller_biasa_tanpa_kapabilitas_apapun_ditolak_403(): void
    {
        $teller = $this->buatTeller();
        $this->mockBridgeKosong();

        $this->actingAs($teller)
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tidak memiliki akses ke halaman ini.');
    }

    public function test_boleh_diakses_user_bisa_akses_laporan(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_boleh_diakses_superadmin_walau_bisa_akses_laporan_dan_bisa_edit_semua_loket_false(): void
    {
        $superadmin = $this->buatSuperadmin([
            'bisa_akses_laporan' => false,
            'bisa_edit_setoran_semua_loket' => false,
        ]);
        $this->mockBridgeKosong();

        $this->actingAs($superadmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_boleh_diakses_user_bisa_edit_setoran_semua_loket_walau_bukan_admin_laporan(): void
    {
        $editor = $this->buatEditorSemuaLoket();
        $this->mockBridgeKosong();

        $this->actingAs($editor)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    // ===================================================================
    // Validasi parameter `tanggal`
    // ===================================================================

    public function test_tanggal_kosong_default_ke_hari_ini(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tanggal', '2026-09-08');
    }

    public function test_tanggal_format_salah_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=08-09-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_tanggal_masa_depan_ditolak_422(): void
    {
        Carbon::setTestNow('2026-09-08');
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-09')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    // ===================================================================
    // Loket mana yang tampil -- union bridge rows & baris lokal (bagian 4c)
    // ===================================================================

    public function test_loket_yang_ada_di_bridge_maupun_lokal_dua_duanya_tampil_loket_lain_tidak_muncul(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');

        $this->buatLoket(1, 'Loket Bridge Saja');
        $this->buatLoket(2, 'Loket Lokal Saja');
        $this->buatLoket(3, 'Loket Tidak Ada Aktivitas');

        $teller = $this->buatTeller();
        $this->buatSetoran($teller, 2, $tanggal, 'draft');

        $this->mockBridge([
            $this->barisBridge(1, 'U001', 'Teller Bridge', 100000),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk();

        $loketIdMuncul = collect($response->json('data.items'))->pluck('loket_id')->all();

        $this->assertEqualsCanonicalizing([1, 2], $loketIdMuncul);
        $this->assertNotContains(3, $loketIdMuncul);
    }

    // ===================================================================
    // Status agregat per loket (bagian 5a) -- draft menang
    // ===================================================================

    public function test_status_draft_menang_walau_ada_teller_lain_yang_sudah_submitted(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        $this->buatLoket(1);

        $tellerA = $this->buatTeller(['nama' => 'Teller A']);
        $tellerB = $this->buatTeller(['nama' => 'Teller B']);
        $this->buatSetoran($tellerA, 1, $tanggal, 'submitted', 50000);
        $this->buatSetoran($tellerB, 1, $tanggal, 'draft');

        $this->mockBridgeKosong();

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame('draft', $item['status']);
    }

    public function test_status_submitted_kalau_tidak_ada_draft_sama_sekali(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        $this->buatLoket(1);
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, 1, $tanggal, 'submitted', 50000);
        $this->mockBridgeKosong();

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame('submitted', $item['status']);
    }

    public function test_status_belum_input_kalau_loket_cuma_muncul_dari_bridge_tanpa_baris_lokal(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);
        $this->mockBridge([$this->barisBridge(1, 'U001', 'Teller Bridge', 100000)]);

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame('belum_input', $item['status']);
    }

    // ===================================================================
    // total_pendapatan_sopp, total_setor, selisih (null-vs-0, bagian 5b/5c/5d)
    // ===================================================================

    public function test_total_pendapatan_sopp_null_kalau_bridge_gagal_total(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        $this->buatLoket(1);
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, 1, $tanggal, 'submitted', 50000);
        $this->mockBridgeGagalTotal();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk();

        $response->assertJsonPath('data.bridge_gagal', true);
        $item = $response->json('data.items.0');
        $this->assertNull($item['total_pendapatan_sopp']);
        $this->assertNull($item['selisih']);
        // total_setor TETAP terisi -- murni dari DB lokal, tidak tergantung bridge.
        $this->assertSame(50000, $item['total_setor']);
    }

    public function test_total_setor_null_kalau_belum_ada_baris_submitted_walau_ada_draft(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);
        $teller = $this->buatTeller();
        // Draft tanpa nominal isi -- tapi walau nominal ada, method
        // totalSetorUntukLoketTanggal() cuma hitung status=submitted,
        // jadi draft dengan nominal manapun tetap tidak masuk hitungan.
        $this->buatSetoran($teller, 1, $tanggal, 'draft', 999999);
        $this->mockBridge([$this->barisBridge(1, 'U001', 'Teller Bridge', 100000)]);

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertNull($item['total_setor']);
        // total_pendapatan_sopp tetap terisi (100000) krn bridge sukses --
        // tapi selisih tetap null krn total_setor null.
        $this->assertSame(100000, $item['total_pendapatan_sopp']);
        $this->assertNull($item['selisih']);
    }

    public function test_selisih_terhitung_benar_kalau_pendapatan_dan_setor_dua_duanya_ada(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);
        $teller = $this->buatTeller();
        $this->buatSetoran($teller, 1, $tanggal, 'submitted', 80000);
        $this->mockBridge([$this->barisBridge(1, 'U001', 'Teller Bridge', 100000)]);

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(100000, $item['total_pendapatan_sopp']);
        $this->assertSame(80000, $item['total_setor']);
        $this->assertSame(20000, $item['selisih']);
    }

    // ===================================================================
    // teller[] (bagian 5e) -- submitted saja, unique, alfabetis
    // ===================================================================

    public function test_teller_ringkas_cuma_yang_submitted_unique_dan_alfabetis(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        $this->buatLoket(1);

        $zaki = $this->buatTeller(['nama' => 'Zaki']);
        $andi = $this->buatTeller(['nama' => 'Andi']);
        $budiDraft = $this->buatTeller(['nama' => 'Budi']);

        $this->buatSetoran($zaki, 1, $tanggal, 'submitted', 10000);
        $this->buatSetoran($andi, 1, $tanggal, 'submitted', 10000);
        $this->buatSetoran($budiDraft, 1, $tanggal, 'draft');
        $this->mockBridgeKosong();

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(['Andi', 'Zaki'], $item['teller']);
    }

    // ===================================================================
    // detail_teller (bagian 5f) -- gabungan lokal+bridge, tanpa nominal,
    // urutan belum-selesai duluan lalu submitted, alfabetis dalam grup
    // ===================================================================

    public function test_detail_teller_gabung_lokal_dan_bridge_tanpa_nominal_dan_terurut_belum_selesai_duluan(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);

        $zakiSubmitted = $this->buatTeller(['nama' => 'Zaki', 'user_id_sopp' => 'U100']);
        $andiDraft = $this->buatTeller(['nama' => 'Andi', 'user_id_sopp' => 'U200']);

        $setoranZaki = $this->buatSetoran($zakiSubmitted, 1, $tanggal, 'submitted', 50000);
        $this->buatSetoran($andiDraft, 1, $tanggal, 'draft', 999999);

        // Baris bridge: user_id U100 sudah punya row lokal (Zaki) --
        // TIDAK boleh dobel muncul. U300 belum punya row lokal sama
        // sekali -- harus muncul sebagai entry belum_input.
        $this->mockBridge([
            $this->barisBridge(1, 'U100', 'Zaki', 50000),
            $this->barisBridge(1, 'U300', 'Citra Bridge', 30000),
        ]);

        $item = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0');

        $detail = $item['detail_teller'];

        $this->assertCount(3, $detail);
        // Tidak ada field nominal apapun di entry manapun.
        foreach ($detail as $entry) {
            $this->assertArrayNotHasKey('nominal', $entry);
            $this->assertArrayNotHasKey('total_setor', $entry);
        }

        // Urutan: belum-selesai (draft + belum_input) duluan, alfabetis;
        // baru submitted.
        $this->assertSame('Andi', $detail[0]['nama']);
        $this->assertSame('draft', $detail[0]['status_workflow']);
        $this->assertNotNull($detail[0]['setoran_id']);

        $this->assertSame('Citra Bridge', $detail[1]['nama']);
        $this->assertSame('belum_input', $detail[1]['status_workflow']);
        $this->assertNull($detail[1]['setoran_id']);

        $this->assertSame('Zaki', $detail[2]['nama']);
        $this->assertSame('submitted', $detail[2]['status_workflow']);
        $this->assertSame($setoranZaki->id, $detail[2]['setoran_id']);
    }

    /**
     * DIKONFIRMASI user: draft & belum_input digabung SATU grup alfabetis
     * murni, TIDAK ada tie-break berdasar tipe di antara keduanya. Test
     * di atas ('...terurut_belum_selesai_duluan') kebetulan namanya
     * draft='Andi' & belum_input='Citra' -- urutan alfabetis & urutan
     * tipe kebetulan SAMA, jadi tidak benar-benar membuktikan tidak ada
     * tie-break tipe (implementasi hipotetis "semua draft duluan, baru
     * semua belum_input" akan lolos test itu juga). Test ini SENGAJA
     * membalik nama supaya dua kemungkinan implementasi menghasilkan
     * urutan BEDA -- draft diberi nama alfabetis BELAKANGAN ('Zeta'),
     * belum_input diberi nama alfabetis DULUAN ('Budi'). Kalau hasilnya
     * Budi-lalu-Zeta, itu murni alfabetis (perilaku yang dikonfirmasi).
     * Kalau hasilnya Zeta-lalu-Budi, berarti ada tie-break tipe
     * (draft-duluan) yang TIDAK sesuai keputusan yang sudah di-ACC.
     */
    public function test_urutan_draft_dan_belum_input_murni_alfabetis_tanpa_tiebreak_tipe(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);

        $zetaDraft = $this->buatTeller(['nama' => 'Zeta', 'user_id_sopp' => 'U100']);
        $this->buatSetoran($zetaDraft, 1, $tanggal, 'draft', 999999);

        // Budi TIDAK punya row lokal sama sekali -- cuma muncul dari
        // bridge -> jadi entry belum_input.
        $this->mockBridge([
            $this->barisBridge(1, 'U999', 'Budi', 40000),
        ]);

        $detail = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->json('data.items.0.detail_teller');

        $this->assertCount(2, $detail);
        $this->assertSame('Budi', $detail[0]['nama']);
        $this->assertSame('belum_input', $detail[0]['status_workflow']);
        $this->assertSame('Zeta', $detail[1]['nama']);
        $this->assertSame('draft', $detail[1]['status_workflow']);
    }

    // ===================================================================
    // Ringkasan 4 kartu (bagian 6)
    // ===================================================================

    public function test_ringkasan_hitung_benar_draft_tidak_masuk_submit_maupun_belum_setor(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');

        $this->buatLoket(1, 'Loket Submitted');
        $this->buatLoket(2, 'Loket Draft');
        $this->buatLoket(3, 'Loket Belum Input');
        $this->buatLoket(4, 'Loket Ada Selisih');

        $t1 = $this->buatTeller();
        $t2 = $this->buatTeller();
        $t4 = $this->buatTeller();
        $this->buatSetoran($t1, 1, $tanggal, 'submitted', 100000);
        $this->buatSetoran($t2, 2, $tanggal, 'draft');
        $this->buatSetoran($t4, 4, $tanggal, 'submitted', 50000); // beda dari bridge -> ada selisih

        $this->mockBridge([
            $this->barisBridge(1, 'U001', 'T1', 100000), // pas, selisih 0
            $this->barisBridge(3, 'U003', 'T3', 20000),  // loket 3 cuma dari bridge -> belum_input
            $this->barisBridge(4, 'U004', 'T4', 90000),  // beda dari total_setor 50000 -> ada selisih
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk();

        $response->assertJsonPath('data.ringkasan.total_loket', 4)
            ->assertJsonPath('data.ringkasan.sudah_submit', 2) // loket 1 & 4
            ->assertJsonPath('data.ringkasan.belum_setor', 1)  // loket 3
            ->assertJsonPath('data.ringkasan.ada_selisih', 1); // loket 4 saja (loket 1 selisihnya 0)
    }

    // ===================================================================
    // Rate limit tombol Refresh (bagian 3) -- window 20 detik
    // ===================================================================

    public function test_refresh_dobel_dalam_20_detik_untuk_tanggal_sama_ditolak_429(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08&refresh=true')
            ->assertStatus(429)
            ->assertJsonPath('message', 'Tunggu beberapa detik sebelum refresh lagi.');
    }

    public function test_get_biasa_tanpa_refresh_tidak_pernah_kena_rate_limit(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)
                ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08')
                ->assertOk();
        }
    }

    public function test_refresh_untuk_tanggal_berbeda_tidak_ikut_kena_limit_dari_tanggal_lain(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-07&refresh=true')
            ->assertOk();
    }

    public function test_refresh_rate_limit_terpisah_per_user(): void
    {
        $adminA = $this->buatAdminLaporan(['nama' => 'Admin A']);
        $adminB = $this->buatAdminLaporan(['nama' => 'Admin B']);
        $this->mockBridgeKosong();

        $this->actingAs($adminA)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08&refresh=true')
            ->assertOk();

        // User BEDA, tanggal SAMA, dalam window 20 detik yang sama --
        // key rate-limit ikut user_id, jadi harus tetap lolos.
        $this->actingAs($adminB)
            ->getJson('/api/v1/admin/dashboard?tanggal=2026-09-08&refresh=true')
            ->assertOk();
    }

    // ===================================================================
    // Cache dipakai bareng Laporan mode=harian (jaminan angka konsisten)
    // ===================================================================

    public function test_cache_dipakai_bareng_laporan_mode_harian_bridge_cuma_dipanggil_sekali(): void
    {
        $admin = $this->buatAdminLaporan();
        $tanggal = '2026-09-08';
        Carbon::setTestNow($tanggal.' 10:00:00');
        $this->buatLoket(1);

        $mock = \Mockery::mock(BridgeService::class);
        $mock->shouldReceive('fetchDetailPerUser')
            ->once() // <- inti pembuktiannya: cuma sekali walau 2 endpoint beda dipanggil
            ->andReturn([
                'data' => [$this->barisBridge(1, 'U001', 'Teller Bridge', 70000)],
                'grand_total' => 70000,
            ]);
        $this->app->instance(BridgeService::class, $mock);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/dashboard?tanggal={$tanggal}")
            ->assertOk()
            ->assertJsonPath('data.items.0.total_pendapatan_sopp', 70000);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/laporan?mode=harian&tanggal={$tanggal}")
            ->assertOk();
    }
}
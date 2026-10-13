<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chunk: Hak Akses (GET/PUT /api/v1/admin/hak-akses).
 *
 * Yang TIDAK bisa dibuktikan test ini: race condition beneran antar dua
 * request paralel. SQLite in-memory (driver test di project ini, lihat
 * phpunit.xml) jalan satu koneksi/satu proses, jadi gak ada cara bikin
 * dua request beneran tabrakan di sini. Yang dites di bagian "stale
 * dari" itu CUMA kebenaran logic pembandingnya (DB diubah manual dulu
 * sebelum request kedua dikirim, mensimulasikan hasil akhir dari race,
 * bukan race-nya sendiri) -- kalau butuh bukti lockForUpdate() beneran
 * ngeblokir dua koneksi nyata, itu butuh test terpisah pakai MySQL/driver
 * yang dukung koneksi paralel sungguhan, gak ada di file ini.
 */
class HakAksesTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loket = Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Satu']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'loket_id' => $this->loket->loket_id,
            'nama' => 'Teller Satu',
        ], $overrides));
    }

    private function buatAdminHakAkses(array $overrides = []): User
    {
        $user = $this->buatTeller(array_merge(['nama' => 'Admin Hak Akses'], $overrides));
        $user->forceFill(['bisa_atur_hak_akses' => true])->save();

        return $user;
    }

    private function buatSuperadmin(array $overrides = []): User
    {
        $user = $this->buatTeller(array_merge(['nama' => 'Super Admin'], $overrides));
        $user->forceFill(['is_superadmin' => true])->save();

        return $user;
    }

    // -----------------------------------------------------------------
    // GET /admin/hak-akses -- auth & authorization
    // -----------------------------------------------------------------

    public function test_get_tanpa_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/admin/hak-akses')->assertStatus(401);
    }

    public function test_get_ditolak_403_kalau_bukan_admin_dan_bukan_superadmin(): void
    {
        $teller = $this->buatTeller();

        $this->actingAs($teller)
            ->getJson('/api/v1/admin/hak-akses')
            ->assertStatus(403);
    }

    public function test_get_boleh_diakses_superadmin_walau_bisa_atur_hak_akses_false(): void
    {
        $superadmin = $this->buatSuperadmin(['bisa_atur_hak_akses' => false]);

        $this->actingAs($superadmin)
            ->getJson('/api/v1/admin/hak-akses')
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // GET /admin/hak-akses -- isi & bentuk data
    // -----------------------------------------------------------------

    public function test_get_mengeluarkan_superadmin_dari_list(): void
    {
        $admin = $this->buatAdminHakAkses();
        $this->buatSuperadmin(['nama' => 'Super Yang Harus Hilang']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/hak-akses');

        $response->assertOk();
        $namaDiList = collect($response->json('data.items'))->pluck('nama');

        $this->assertFalse(
            $namaDiList->contains('Super Yang Harus Hilang'),
            'Superadmin harusnya gak pernah muncul di list ini. Isi: '.$namaDiList->implode(', ')
        );
        // admin (bukan superadmin) sendiri HARUS tetap muncul.
        $this->assertTrue($namaDiList->contains('Admin Hak Akses'));
    }

    public function test_get_field_username_pakai_user_id_sopp_buat_user_asal_sso(): void
    {
        $admin = $this->buatAdminHakAkses();
        $teller = $this->buatTeller(['user_id_sopp' => 'U99999', 'nama' => 'Teller SSO']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/hak-akses');

        $item = collect($response->json('data.items'))->firstWhere('nama', 'Teller SSO');
        $this->assertSame('U99999', $item['username']);
    }

    /**
     * Edge case sintetis: user non-superadmin yang user_id_sopp-nya
     * NULL tapi kolom username terisi. Kombinasi ini gak lazim di data
     * produksi (komentar UserFactory bilang username biasanya cuma
     * dipakai superadmin), tapi mapUser() punya fallback buat kasus
     * ini (`user_id_sopp ?? username`) jadi tetap perlu dibuktikan.
     */
    public function test_get_field_username_fallback_ke_kolom_username_kalau_user_id_sopp_null(): void
    {
        $admin = $this->buatAdminHakAkses();
        $user = $this->buatTeller(['user_id_sopp' => null, 'nama' => 'User Username Only']);
        $user->forceFill(['username' => 'usernameasli'])->save();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/hak-akses');

        $item = collect($response->json('data.items'))->firstWhere('nama', 'User Username Only');
        $this->assertSame('usernameasli', $item['username']);
    }

    public function test_get_field_loket_ambil_nama_loket_yang_benar(): void
    {
        $admin = $this->buatAdminHakAkses();
        $lain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Dua']);
        $teller = $this->buatTeller(['loket_id' => 2, 'nama' => 'Teller Loket Dua']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/hak-akses');

        $item = collect($response->json('data.items'))->firstWhere('nama', 'Teller Loket Dua');
        $this->assertSame('Loket Dua', $item['loket']);
    }

    /**
     * Query buat ambil nama loket HARUS konstan, gak boleh naik seiring
     * jumlah user di halaman (N+1 check, niru pola
     * PelunasanFixVerificationTest::test_daftar_tanggal_tersedia_tidak_n_plus_1).
     */
    public function test_get_tidak_n_plus_1_query_buat_nama_loket(): void
    {
        $admin = $this->buatAdminHakAkses();
        foreach (range(1, 10) as $i) {
            $this->buatTeller(['nama' => "Teller {$i}"]);
        }

        $jumlahQuery = 0;
        DB::listen(function () use (&$jumlahQuery) {
            $jumlahQuery++;
        });

        $this->actingAs($admin)->getJson('/api/v1/admin/hak-akses')->assertOk();

        // Sebelum fix N+1: ~1 (query user) + 10 (satu per user buat loket).
        // Setelah fix: konstan kecil. Threshold 6 dikasih longgar buat
        // query lain (auth check, dst), yang penting bukan 10+.
        $this->assertLessThan(
            6,
            $jumlahQuery,
            "Jumlah query = {$jumlahQuery}, indikasi N+1 masih ada."
        );
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- auth & authorization
    // -----------------------------------------------------------------

    public function test_put_tanpa_login_ditolak_401(): void
    {
        $this->putJson('/api/v1/admin/hak-akses', ['perubahan' => []])->assertStatus(401);
    }

    public function test_put_ditolak_403_kalau_bukan_admin(): void
    {
        $teller = $this->buatTeller();
        $target = $this->buatTeller(['nama' => 'Target']);

        $this->actingAs($teller)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ],
            ])
            ->assertStatus(403);

        $this->assertFalse($target->fresh()->bisa_akses_laporan);
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- validasi payload
    // -----------------------------------------------------------------

    public function test_put_perubahan_kosong_ditolak_422(): void
    {
        $admin = $this->buatAdminHakAkses();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', ['perubahan' => []])
            ->assertStatus(422);
    }

    public function test_put_tipe_role_ditolak(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'role', 'dari' => 'teller', 'ke' => 'verifikator'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_put_tipe_aktif_ditolak(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'aktif', 'dari' => true, 'ke' => false],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_put_id_tidak_ditemukan_ditolak_422(): void
    {
        $admin = $this->buatAdminHakAkses();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => 999999, 'nama' => 'Ghost', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_put_target_superadmin_ditolak_walau_id_dikirim_manual(): void
    {
        $admin = $this->buatAdminHakAkses();
        $superadmin = $this->buatSuperadmin(['nama' => 'Super Target']);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => $superadmin->id, 'nama' => 'Super Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ],
            ])
            ->assertStatus(422);

        $this->assertFalse($superadmin->fresh()->bisa_akses_laporan);
    }

    public function test_put_duplikat_id_dan_tipe_dalam_satu_batch_ditolak_422(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/hak-akses', [
                'perubahan' => [
                    ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                    ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => true, 'ke' => false],
                ],
            ])
            ->assertStatus(422);

        $this->assertFalse($target->fresh()->bisa_akses_laporan);
    }

    /**
     * id+tipe yang sama boleh dikirim beda kolom (tipe beda) buat user
     * yang sama dalam satu batch -- yang dilarang cuma pasangan
     * (id,tipe) yang identik, bukan id-nya doang.
     */
    public function test_put_id_sama_tipe_beda_dalam_satu_batch_boleh(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_lihat_log', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertOk();
        $target->refresh();
        $this->assertTrue($target->bisa_akses_laporan);
        $this->assertTrue($target->bisa_lihat_log);
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- self-block
    // -----------------------------------------------------------------

    public function test_put_self_edit_ditolak_403_termasuk_superadmin(): void
    {
        $admin = $this->buatAdminHakAkses();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $admin->id, 'nama' => $admin->nama, 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertFalse($admin->fresh()->bisa_akses_laporan);
    }

    public function test_put_self_edit_superadmin_juga_ditolak(): void
    {
        $superadmin = $this->buatSuperadmin();

        $response = $this->actingAs($superadmin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $superadmin->id, 'nama' => $superadmin->nama, 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- validasi `dari` (stale check)
    // -----------------------------------------------------------------

    public function test_put_dari_stale_ditolak_409_dan_tidak_menulis(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);
        // Nilai asli di DB: false. Client kirim dari=true (udah gak sinkron).
        $target->forceFill(['bisa_akses_laporan' => false])->save();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => true, 'ke' => false],
            ],
        ]);

        $response->assertStatus(409);
        $this->assertFalse($target->fresh()->bisa_akses_laporan, 'Gak boleh berubah sama sekali kalau dari stale.');
    }

    public function test_put_dari_cocok_boolean_gaya_1_0_tetap_lolos(): void
    {
        // Buktiin fix strict-comparison: dari dikirim sebagai 1 (bukan
        // literal true), harus tetap dianggap sama dengan nilai DB false
        // -- err, di sini nilai DB awalnya false, jadi kita test arah
        // sebaliknya: DB true, client kirim dari=1.
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);
        $target->forceFill(['bisa_akses_laporan' => true])->save();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => 1, 'ke' => false],
            ],
        ]);

        $response->assertOk();
        $this->assertFalse($target->fresh()->bisa_akses_laporan);
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- all-or-nothing
    // -----------------------------------------------------------------

    public function test_put_batch_campur_valid_dan_invalid_tidak_ada_yang_ke_apply(): void
    {
        $admin = $this->buatAdminHakAkses();
        $targetValid = $this->buatTeller(['nama' => 'Target Valid']);
        $targetValid->forceFill(['bisa_akses_laporan' => false])->save();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $targetValid->id, 'nama' => 'Target Valid', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ['id' => 999999, 'nama' => 'Ghost', 'tipe' => 'bisa_lihat_log', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertFalse(
            $targetValid->fresh()->bisa_akses_laporan,
            'Item pertama valid tapi harus IKUT batal karena item kedua invalid (all-or-nothing).'
        );
    }

    // -----------------------------------------------------------------
    // PUT /admin/hak-akses -- sukses
    // -----------------------------------------------------------------

    public function test_put_sukses_mengubah_kolom_dan_response_data_null(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);
        $target->forceFill(['bisa_akses_laporan' => false])->save();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertOk()->assertExactJson(['status' => 'ok', 'data' => null]);
        $this->assertTrue($target->fresh()->bisa_akses_laporan);
    }

    public function test_put_sukses_mencatat_satu_activity_log_per_item(): void
    {
        $admin = $this->buatAdminHakAkses();
        $target = $this->buatTeller(['nama' => 'Target']);
        $target->forceFill(['bisa_akses_laporan' => false, 'bisa_lihat_log' => false])->save();

        $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ['id' => $target->id, 'nama' => 'Target', 'tipe' => 'bisa_lihat_log', 'dari' => false, 'ke' => true],
            ],
        ])->assertOk();

        $logs = ActivityLog::where('user_id', $admin->id)
            ->where('jenis_aksi', 'ubah_kapabilitas')
            ->where('target_id', $target->id)
            ->get();

        $this->assertCount(2, $logs, 'Harus satu row log per item perubahan, bukan satu row gabungan.');

        $logLaporan = $logs->first(fn ($l) => ($l->detail_perubahan['sesudah']['bisa_akses_laporan'] ?? null) === true);
        $this->assertNotNull($logLaporan);
        $this->assertSame(['bisa_akses_laporan' => false], $logLaporan->detail_perubahan['sebelum']);
        $this->assertSame(['bisa_akses_laporan' => true], $logLaporan->detail_perubahan['sesudah']);
    }

    public function test_put_batch_lintas_beberapa_user_semua_ke_apply(): void
    {
        $admin = $this->buatAdminHakAkses();
        $targetA = $this->buatTeller(['nama' => 'Target A']);
        $targetB = $this->buatTeller(['nama' => 'Target B']);
        $targetA->forceFill(['bisa_akses_laporan' => false])->save();
        $targetB->forceFill(['bisa_lihat_log' => false])->save();

        $response = $this->actingAs($admin)->putJson('/api/v1/admin/hak-akses', [
            'perubahan' => [
                ['id' => $targetA->id, 'nama' => 'Target A', 'tipe' => 'bisa_akses_laporan', 'dari' => false, 'ke' => true],
                ['id' => $targetB->id, 'nama' => 'Target B', 'tipe' => 'bisa_lihat_log', 'dari' => false, 'ke' => true],
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($targetA->fresh()->bisa_akses_laporan);
        $this->assertTrue($targetB->fresh()->bisa_lihat_log);
    }
}
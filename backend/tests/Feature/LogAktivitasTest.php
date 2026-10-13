<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint: GET /api/v1/admin/log-aktivitas (LogAktivitasController).
 * Rujukan lengkap semua keputusan: dokumen "spek-log-aktivitas-admin.md".
 * Nomor bagian di komentar bawah ini merujuk ke situ.
 *
 * =====================================================================
 * TEMUAN PAS NULIS TEST INI (regression risk, BUKAN diasumsikan lolos):
 *
 *   Test `RiwayatSetoranTest::test_destroy_tercatat_ke_activity_log`
 *   (file lain) meng-assert `target_type => SetoranHarian::class` untuk
 *   log `setoran_dihapus` -- itu PERSIS bug yang diperbaiki lewat
 *   PATCH_RiwayatSetoranController.txt (target_type seharusnya literal
 *   'setoran_harian', konsisten dengan seluruh pemanggilan catat() lain).
 *   Begitu patch itu diterapkan, test tersebut BAKAL GAGAL kalau
 *   assertion-nya tidak ikut diupdate ke 'setoran_harian'. Test
 *   `test_regresi_target_type_setoran_dihapus_sudah_literal_string` di
 *   bawah ini SENGAJA ditambahkan sebagai regression guard buat bug
 *   yang sama, dari sisi LogAktivitasController.
 * =====================================================================
 */
class LogAktivitasTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loketA;
    private Loket $loketB;

    private const ENDPOINT = '/api/v1/admin/log-aktivitas';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loketA = Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->loketB = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket B']);

        Carbon::setTestNow('2026-09-02 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'loket_id' => $this->loketA->loket_id,
            'nama' => 'Teller Satu',
        ], $overrides));
    }

    private function buatVerifikatorLihatLog(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['bisa_lihat_log' => true])->save();

        return $user;
    }

    private function buatSuperadmin(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['is_superadmin' => true])->save();

        return $user;
    }

    private function buatSetoran(
        User $teller,
        string $tanggal,
        string $status = 'submitted',
        ?Loket $loket = null,
    ): SetoranHarian {
        return SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => ($loket ?? $this->loketA)->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);
    }

    private function buatEntry(SetoranHarian $setoran, array $overrides = []): SetoranDetail
    {
        return SetoranDetail::create(array_merge([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
            'foto_bukti_path' => 'bukti/x.jpg',
            'is_konfirmasi' => true,
        ], $overrides));
    }

    // ===================================================================
    // Sesi & otorisasi (Bagian 0)
    // ===================================================================

    public function test_belum_login_ditolak_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_teller_biasa_tanpa_bisa_lihat_log_ditolak_403(): void
    {
        $teller = $this->buatTeller();

        $this->actingAs($teller)->getJson(self::ENDPOINT)->assertStatus(403);
    }

    public function test_user_dengan_kapabilitas_bisa_lihat_log_boleh_akses(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        $this->actingAs($verifikator)->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_superadmin_boleh_akses_walau_kolom_bisa_lihat_log_false(): void
    {
        // kapabilitasUntukApi() otomatis meng-OR-kan is_superadmin --
        // guard TIDAK boleh cek bisa_lihat_log mentah, harus lewat method itu.
        $superadmin = $this->buatSuperadmin();

        $this->actingAs($superadmin)->getJson(self::ENDPOINT)->assertOk();
    }

    // ===================================================================
    // Struktur dasar & urutan (Bagian 0, 1)
    // ===================================================================

    public function test_index_kosong_kalau_belum_ada_log(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.meta.total_items', 0);
    }

    public function test_urutan_terbaru_dulu(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        Carbon::setTestNow('2026-09-01 08:00:00');
        ActivityLog::catat($verifikator, 'login_manual');

        Carbon::setTestNow('2026-09-02 08:00:00');
        ActivityLog::catat($verifikator, 'logout_manual');

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.aksi', 'logout_manual')
            ->assertJsonPath('data.items.1.aksi', 'login_manual');
    }

    public function test_paginasi_meta(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        for ($i = 0; $i < 3; $i++) {
            ActivityLog::catat($verifikator, 'login_manual');
        }

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 2)
            ->assertJsonPath('data.meta.total_items', 3)
            ->assertJsonPath('data.meta.total_pages', 2);
    }

    public function test_filter_aksi(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();
        ActivityLog::catat($verifikator, 'login_manual');
        ActivityLog::catat($verifikator, 'logout_manual');

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?aksi=login_manual');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.aksi', 'login_manual');
    }

    public function test_filter_aksi_nilai_tidak_valid_ditolak_422(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?aksi=APPROVE');

        $response->assertStatus(422);
    }

    public function test_filter_rentang_tanggal(): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();

        Carbon::setTestNow('2026-08-01 08:00:00');
        ActivityLog::catat($verifikator, 'login_manual');

        Carbon::setTestNow('2026-08-15 08:00:00');
        ActivityLog::catat($verifikator, 'logout_manual');

        $response = $this->actingAs($verifikator)->getJson(
            self::ENDPOINT . '?tanggal_mulai=2026-08-10&tanggal_akhir=2026-08-20'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.aksi', 'logout_manual');
    }

    // ===================================================================
    // Field `role` (Bagian 2.3)
    // ===================================================================

    public function test_role_ambil_dari_actor_role_yang_sudah_tersimpan(): void
    {
        $superadmin = $this->buatSuperadmin();
        ActivityLog::catat($superadmin, 'login_manual');

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.role', 'superadmin');
    }

    // ===================================================================
    // Field `loket` -- loket TARGET, bukan loket pelaku (Bagian 2.4)
    // ===================================================================

    public function test_loket_pakai_loket_target_bukan_loket_pelaku(): void
    {
        // Verifikator berbasis Loket A, tapi mengedit setoran di Loket B.
        $verifikator = $this->buatVerifikatorLihatLog(['loket_id' => $this->loketA->loket_id]);
        $teller = $this->buatTeller(['loket_id' => $this->loketB->loket_id]);
        $setoranDiLoketB = $this->buatSetoran($teller, '2026-08-01', 'submitted', $this->loketB);
        $entry = $this->buatEntry($setoranDiLoketB);

        ActivityLog::catat(
            actor: $verifikator,
            jenisAksi: 'edit_setoran',
            objectType: 'setoran_detail',
            objectId: $entry->id,
            detailPerubahan: [
                'setoran_harian_id' => $setoranDiLoketB->id,
                'sebelum' => ['nominal' => 100000],
                'sesudah' => ['nominal' => 150000],
            ],
        );

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        // Loket B (loket TARGET/setoran), BUKAN Loket A (loket verifikator sendiri).
        $response->assertOk()->assertJsonPath('data.items.0.loket', 'Loket B');
    }

    public function test_loket_null_untuk_aksi_system_wide(): void
    {
        $superadmin = $this->buatSuperadmin();
        ActivityLog::catat($superadmin, 'login_manual');

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.loket', null);
    }

    // ===================================================================
    // Field `target` (Bagian 2.6) -- format & fallback setelah hard delete
    // ===================================================================

    public function test_target_format_setoran_harian(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-15');
        ActivityLog::catat($teller, 'submit_setoran', 'setoran_harian', $setoran->id, [
            'tanggal_transaksi' => '2026-08-15',
            'loket_id' => $this->loketA->loket_id,
            'sebelum' => ['status' => 'draft'],
            'sesudah' => ['status' => 'submitted'],
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.target', 'Setoran 2026-08-15');
    }

    public function test_target_format_setoran_detail(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-15');
        $entry = $this->buatEntry($setoran, ['metode_pembayaran' => 'transfer']);
        ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entry->id, [
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 100000,
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.target', "Detail Setoran #{$entry->id} (transfer)");
    }

    public function test_target_format_users(): void
    {
        $superadmin = $this->buatSuperadmin();
        $userLain = $this->buatTeller(['nama' => 'Budi Santoso']);
        // 'auto_provisioning' -- jenis aksi nyata yang target_type-nya 'users'
        // (SsoController), dipakai di sini murni buat nguji format target,
        // detail_perubahan diisi apa adanya sesuai bentuk aslinya.
        ActivityLog::catat($superadmin, 'auto_provisioning', 'users', $userLain->id, [
            'user_id_sopp' => $userLain->user_id_sopp,
            'loket_id' => $userLain->loket_id,
        ]);

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.target', 'User: Budi Santoso');
    }

    public function test_target_null_untuk_aksi_tanpa_target(): void
    {
        $superadmin = $this->buatSuperadmin();
        ActivityLog::catat($superadmin, 'login_manual');

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.target', null);
    }

    public function test_target_fallback_ke_snapshot_setelah_setoran_dihapus_permanen(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-20');
        $idSetoranLama = $setoran->id;

        ActivityLog::catat($teller, 'setoran_dihapus', 'setoran_harian', $idSetoranLama, [
            'tanggal_transaksi' => '2026-08-20',
            'loket_id' => $this->loketA->loket_id,
            'status_saat_dihapus' => 'submitted',
            'jumlah_entry_dihapus' => 1,
            'alasan' => 'Salah input loket.',
        ]);

        // Hard delete beneran -- row setoran_harian-nya HILANG dari DB.
        $setoran->delete();
        // FIX: SetoranHarian pakai SoftDeletes -- delete() nyisain row-nya
        // (deleted_at keisi), bukan hard delete fisik. assertSoftDeleted,
        // BUKAN assertDatabaseMissing. Query Eloquent biasa (whereIn/find)
        // otomatis nyaring row soft-deleted, jadi resolver di controller
        // tetap jatuh ke fallback snapshot -- perilaku yang diuji di bawah
        // TETAP valid, cuma cara ngecek "sudah dihapus"-nya yang salah.
        $this->assertSoftDeleted('setoran_harian', ['id' => $idSetoranLama]);

        $verifikator = $this->buatVerifikatorLihatLog();
        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        // Live lookup gagal (row sudah tidak ada) -- harus fallback ke
        // tanggal_transaksi yang sudah tersimpan di detail_perubahan.
        $response->assertOk()->assertJsonPath('data.items.0.target', 'Setoran 2026-08-20');
    }

    public function test_target_fallback_ke_snapshot_setelah_hapus_setoran_entry(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-20');
        $entry = $this->buatEntry($setoran, ['metode_pembayaran' => 'kpay']);
        $idEntryLama = $entry->id;

        ActivityLog::catat($teller, 'hapus_setoran', 'setoran_detail', $idEntryLama, [
            'setoran_harian_id' => $setoran->id,
            'entry' => $entry->only(['metode_pembayaran', 'nominal', 'foto_bukti_path']),
        ]);

        $entry->delete();
        $this->assertDatabaseMissing('setoran_detail', ['id' => $idEntryLama]);

        $verifikator = $this->buatVerifikatorLihatLog();
        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.target', "Detail Setoran #{$idEntryLama} (kpay)");
    }

    // ===================================================================
    // Field `perubahan[]` -- mapping per jenis_aksi (Bagian 2.7)
    // ===================================================================

    public function test_perubahan_edit_setoran_hanya_field_yang_berbeda_nilainya(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01');
        $entry = $this->buatEntry($setoran);

        ActivityLog::catat($teller, 'edit_setoran', 'setoran_detail', $entry->id, [
            'setoran_harian_id' => $setoran->id,
            // metode_pembayaran SAMA di sebelum & sesudah -- tidak boleh
            // ikut muncul di perubahan[]. Cuma nominal yang beda.
            'sebelum' => ['metode_pembayaran' => 'tunai', 'nominal' => 100000],
            'sesudah' => ['metode_pembayaran' => 'tunai', 'nominal' => 150000],
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items.0.perubahan')
            ->assertJsonPath('data.items.0.perubahan.0.label', 'nominal')
            ->assertJsonPath('data.items.0.perubahan.0.before', '100000')
            ->assertJsonPath('data.items.0.perubahan.0.after', '150000');
    }

    public function test_perubahan_edit_setoran_boolean_jadi_ya_tidak(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01');
        $entry = $this->buatEntry($setoran);

        ActivityLog::catat($teller, 'edit_setoran', 'setoran_detail', $entry->id, [
            'setoran_harian_id' => $setoran->id,
            'sebelum' => ['is_konfirmasi' => false],
            'sesudah' => ['is_konfirmasi' => true],
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.perubahan.0.before', 'Tidak')
            ->assertJsonPath('data.items.0.perubahan.0.after', 'Ya');
    }

    public function test_perubahan_hapus_setoran_before_isi_after_null(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01');
        $entry = $this->buatEntry($setoran, ['metode_pembayaran' => 'transfer', 'nominal' => 75000]);

        ActivityLog::catat($teller, 'hapus_setoran', 'setoran_detail', $entry->id, [
            'setoran_harian_id' => $setoran->id,
            'entry' => $entry->only(['metode_pembayaran', 'nominal', 'foto_bukti_path']),
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);
        $perubahan = $response->json('data.items.0.perubahan');

        $response->assertOk();
        $this->assertContains(
            ['label' => 'metode_pembayaran', 'before' => 'transfer', 'after' => null],
            $perubahan
        );
        $this->assertContains(
            ['label' => 'nominal', 'before' => '75000', 'after' => null],
            $perubahan
        );
    }

    public function test_perubahan_setoran_dihapus_alasan_dibalik_arah_dan_loket_id_disembunyikan(): void
    {
        $teller = $this->buatTeller();
        ActivityLog::catat($teller, 'setoran_dihapus', 'setoran_harian', 999, [
            'tanggal_transaksi' => '2026-08-01',
            'loket_id' => $this->loketA->loket_id,
            'status_saat_dihapus' => 'submitted',
            'jumlah_entry_dihapus' => 2,
            'alasan' => 'Duplikat input.',
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);
        $perubahan = collect($response->json('data.items.0.perubahan'));

        $response->assertOk();

        $baris_alasan = $perubahan->firstWhere('label', 'Alasan Penghapusan');
        $this->assertNotNull($baris_alasan);
        $this->assertNull($baris_alasan['before']);
        $this->assertSame('Duplikat input.', $baris_alasan['after']);

        // loket_id TIDAK boleh muncul sebagai baris sendiri di perubahan[]
        // (sudah dipakai buat kolom `loket` terpisah).
        $this->assertNull($perubahan->firstWhere('label', 'loket_id'));
    }

    public function test_perubahan_setoran_dihapus_tanpa_alasan_draft_tidak_ada_baris_alasan(): void
    {
        $teller = $this->buatTeller();
        ActivityLog::catat($teller, 'setoran_dihapus', 'setoran_harian', 998, [
            'tanggal_transaksi' => '2026-08-01',
            'loket_id' => $this->loketA->loket_id,
            'status_saat_dihapus' => 'draft',
            'jumlah_entry_dihapus' => 0,
            'alasan' => null,
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);
        $perubahan = collect($response->json('data.items.0.perubahan'));

        $response->assertOk();
        $this->assertNull($perubahan->firstWhere('label', 'Alasan Penghapusan'));
    }

    public function test_perubahan_submit_setoran_status_draft_ke_submitted(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted');

        ActivityLog::catat($teller, 'submit_setoran', 'setoran_harian', $setoran->id, [
            'tanggal_transaksi' => '2026-08-01',
            'loket_id' => $this->loketA->loket_id,
            'sebelum' => ['status' => 'draft'],
            'sesudah' => ['status' => 'submitted'],
        ]);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items.0.perubahan')
            ->assertJsonPath('data.items.0.perubahan.0.label', 'status')
            ->assertJsonPath('data.items.0.perubahan.0.before', 'draft')
            ->assertJsonPath('data.items.0.perubahan.0.after', 'submitted');
    }

    public function test_perubahan_mutasi_loket_before_after_nama_loket(): void
    {
        $superadmin = $this->buatSuperadmin();
        $tellerYangPindah = $this->buatTeller(['loket_id' => $this->loketB->loket_id]);

        ActivityLog::catat($superadmin, 'mutasi_loket_terdeteksi', 'users', $tellerYangPindah->id, [
            'loket_lama' => $this->loketA->loket_id,
            'loket_baru' => $this->loketB->loket_id,
        ]);

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.perubahan.0.label', 'Loket')
            ->assertJsonPath('data.items.0.perubahan.0.before', 'Loket A')
            ->assertJsonPath('data.items.0.perubahan.0.after', 'Loket B');
    }

    public function test_perubahan_mutasi_loket_before_null_kalau_belum_pernah_ada_loket(): void
    {
        $superadmin = $this->buatSuperadmin();
        $userBaru = $this->buatTeller(['loket_id' => $this->loketA->loket_id]);

        ActivityLog::catat($superadmin, 'mutasi_loket_terdeteksi', 'users', $userBaru->id, [
            'loket_lama' => null,
            'loket_baru' => $this->loketA->loket_id,
        ]);

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.perubahan.0.before', null)
            ->assertJsonPath('data.items.0.perubahan.0.after', 'Loket A');
    }

    /**
     * Aksi create dan aksi tanpa perubahan data -> perubahan[] harus kosong.
     */
    #[DataProvider('aksiTanpaPerubahanProvider')]
    public function test_perubahan_kosong_untuk_aksi_create_dan_tanpa_perubahan(string $jenisAksi): void
    {
        $teller = $this->buatTeller();
        ActivityLog::catat($teller, $jenisAksi);
        $verifikator = $this->buatVerifikatorLihatLog();

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonPath('data.items.0.perubahan', []);
    }

    public static function aksiTanpaPerubahanProvider(): array
    {
        return [
            'buat_setoran' => ['buat_setoran'],
            'buat_pelunasan' => ['buat_pelunasan'],
            'auto_provisioning' => ['auto_provisioning'],
            'vendor_baru_terdeteksi' => ['vendor_baru_terdeteksi'],
            'login_manual' => ['login_manual'],
            'login_gagal' => ['login_gagal'],
            'logout_manual' => ['logout_manual'],
            'riwayat_lintas_loket_dilihat' => ['riwayat_lintas_loket_dilihat'],
            'riwayat_lintas_loket_diekspor' => ['riwayat_lintas_loket_diekspor'],
            // atur_password SENGAJA kosong (keamanan -- detail_perubahan
            // tidak pernah berisi hash/plaintext password), BEDA alasan
            // sama ubah_kapabilitas yang dites terpisah di bawah karena
            // itu justru HARUS ada isinya.
            'atur_password' => ['atur_password'],
            'laporan_dilihat' => ['laporan_dilihat'],
            'laporan_diekspor' => ['laporan_diekspor'],
            'laporan_diunduh' => ['laporan_diunduh'],
        ];
    }

    /**
     * REGRESI (audit Log Aktivitas): ubah_kapabilitas nulis detail_perubahan
     * dengan shape sebelum/sesudah, jadi HARUS didiff seperti edit_setoran --
     * sebelumnya kelewat gak dimasukkan ke match() di resolvePerubahan()
     * sehingga perubahan[] selalu kosong walau datanya beneran ada.
     */
    public function test_perubahan_ubah_kapabilitas_before_after_ya_tidak(): void
    {
        $superadmin = $this->buatSuperadmin();
        $target = $this->buatTeller();

        ActivityLog::catat($superadmin, 'ubah_kapabilitas', 'users', $target->id, [
            'sebelum' => ['bisa_lihat_log' => false],
            'sesudah' => ['bisa_lihat_log' => true],
        ]);

        $response = $this->actingAs($superadmin)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.items.0.perubahan.0.label', 'bisa_lihat_log')
            ->assertJsonPath('data.items.0.perubahan.0.before', 'Tidak')
            ->assertJsonPath('data.items.0.perubahan.0.after', 'Ya');
    }

    /**
     * REGRESI (audit Log Aktivitas): 5 jenis_aksi ini beneran ditulis ke
     * activity_logs oleh chunk lain (Hak Akses, Ganti Password, Laporan)
     * tapi kelewat gak dimasukkan ke JENIS_AKSI_VALID -- filter ?aksi=
     * salah nolak 422 padahal datanya valid.
     */
    #[DataProvider('jenisAksiSebelumnyaHilangDariWhitelistProvider')]
    public function test_filter_aksi_menerima_jenis_aksi_yang_sebelumnya_kelewat(string $jenisAksi): void
    {
        $verifikator = $this->buatVerifikatorLihatLog();
        ActivityLog::catat($verifikator, $jenisAksi);

        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?aksi=' . $jenisAksi);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.aksi', $jenisAksi);
    }

    public static function jenisAksiSebelumnyaHilangDariWhitelistProvider(): array
    {
        return [
            'ubah_kapabilitas' => ['ubah_kapabilitas'],
            'atur_password' => ['atur_password'],
            'laporan_dilihat' => ['laporan_dilihat'],
            'laporan_diekspor' => ['laporan_diekspor'],
            'laporan_diunduh' => ['laporan_diunduh'],
        ];
    }

    // ===================================================================
    // Query param `search` (Bagian 2.8)
    // ===================================================================

    public function test_search_numerik_match_setoran_id_lintas_metode_pembayaran(): void
    {
        $teller = $this->buatTeller();
        $setoran42 = $this->buatSetoran($teller, '2026-08-01');
        $entryTunai = $this->buatEntry($setoran42, ['metode_pembayaran' => 'tunai']);
        $entryTransfer = $this->buatEntry($setoran42, ['metode_pembayaran' => 'transfer']);

        $setoranLain = $this->buatSetoran($teller, '2026-08-02');

        ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entryTunai->id, [
            'setoran_harian_id' => $setoran42->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000,
        ]);
        ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entryTransfer->id, [
            'setoran_harian_id' => $setoran42->id, 'metode_pembayaran' => 'transfer', 'nominal' => 50000,
        ]);
        ActivityLog::catat($teller, 'submit_setoran', 'setoran_harian', $setoranLain->id, [
            'tanggal_transaksi' => '2026-08-02', 'loket_id' => $this->loketA->loket_id,
            'sebelum' => ['status' => 'draft'], 'sesudah' => ['status' => 'submitted'],
        ]);

        $verifikator = $this->buatVerifikatorLihatLog();
        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . "?search={$setoran42->id}");

        // Dua entry (metode beda-beda) di bawah setoran42 ikut ketemu,
        // log dari setoran lain TIDAK ikut.
        $response->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_search_teks_match_metode_pembayaran_spesifik(): void
    {
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01');
        $entryTransfer = $this->buatEntry($setoran, ['metode_pembayaran' => 'transfer']);
        $entryTunai = $this->buatEntry($setoran, ['metode_pembayaran' => 'tunai']);

        ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entryTransfer->id, [
            'setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'transfer', 'nominal' => 50000,
        ]);
        ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entryTunai->id, [
            'setoran_harian_id' => $setoran->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000,
        ]);

        $verifikator = $this->buatVerifikatorLihatLog();
        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?search=transfer');

        // Entry metode 'tunai' dengan setoran_harian_id SAMA tidak ikut.
        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.target', "Detail Setoran #{$entryTransfer->id} (transfer)");
    }

    public function test_search_match_nama_pelaku_bukan_nama_target(): void
    {
        $superadmin = $this->buatSuperadmin(['nama' => 'Admin Utama']);
        $userTarget = $this->buatTeller(['nama' => 'Budi Santoso']);

        // 'auto_provisioning' -- jenis aksi nyata yang target_type-nya 'users'.
        ActivityLog::catat($superadmin, 'auto_provisioning', 'users', $userTarget->id, [
            'user_id_sopp' => $userTarget->user_id_sopp,
            'loket_id' => $userTarget->loket_id,
        ]);

        // Cari "Budi" (nama TARGET, bukan pelaku) -- tidak boleh ketemu,
        // search cuma match nama PELAKU aksi.
        $responseTarget = $this->actingAs($superadmin)->getJson(self::ENDPOINT . '?search=Budi');
        $responseTarget->assertOk()->assertJsonCount(0, 'data.items');

        // Cari "Admin" (nama PELAKU) -- harus ketemu.
        $responsePelaku = $this->actingAs($superadmin)->getJson(self::ENDPOINT . '?search=Admin');
        $responsePelaku->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_search_match_nama_loket(): void
    {
        $teller = $this->buatTeller();
        $setoranDiLoketB = $this->buatSetoran($teller, '2026-08-01', 'submitted', $this->loketB);

        ActivityLog::catat($teller, 'submit_setoran', 'setoran_harian', $setoranDiLoketB->id, [
            'tanggal_transaksi' => '2026-08-01', 'loket_id' => $this->loketB->loket_id,
            'sebelum' => ['status' => 'draft'], 'sesudah' => ['status' => 'submitted'],
        ]);

        $verifikator = $this->buatVerifikatorLihatLog();
        $response = $this->actingAs($verifikator)->getJson(self::ENDPOINT . '?search=' . urlencode('Loket B'));

        $response->assertOk()->assertJsonCount(1, 'data.items');
    }

    // ===================================================================
    // Regresi target_loket_id & bug target_type (Bagian 2.4, 2.6)
    // ===================================================================

    public function test_regresi_target_loket_id_terisi_otomatis_dari_setoran_detail(): void
    {
        // Pastikan derivasi target_loket_id (lewat setoran_harian_id di
        // detail_perubahan) beneran jalan, bukan cuma asumsi di dokumen.
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'submitted', $this->loketB);
        $entry = $this->buatEntry($setoran);

        $log = ActivityLog::catat($teller, 'buat_setoran', 'setoran_detail', $entry->id, [
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
        ]);

        $this->assertSame($this->loketB->loket_id, $log->fresh()->target_loket_id);
    }

    public function test_regresi_target_type_setoran_dihapus_sudah_literal_string(): void
    {
        // Guard buat bug yang diperbaiki lewat PATCH_RiwayatSetoranController.txt
        // -- target_type harus 'setoran_harian' (literal), BUKAN
        // App\Models\SetoranHarian (SetoranHarian::class). Kalau test ini
        // gagal, patch belum diterapkan / belum lengkap.
        $teller = $this->buatTeller();
        $setoran = $this->buatSetoran($teller, '2026-08-01', 'draft');

        $response = $this->actingAs($teller)->deleteJson("/api/v1/teller/setoran/riwayat/{$setoran->id}");
        $response->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'jenis_aksi' => 'setoran_dihapus',
            'target_type' => 'setoran_harian',
        ]);
    }
}
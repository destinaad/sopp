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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chunk: Detail Setoran (GET/PUT /api/v1/teller/setoran/{id}).
 * Rujukan: dokumen "Kontrak Detail Setoran -- Working Doc (Revisi)".
 * DetailSetoranController.php versi lama TIDAK dipakai rujukan sama
 * sekali -- ditulis ulang niru pola Card A/Pelunasan/Riwayat.
 *
 * CATATAN SCOPE petugas(): 'input' bersumber dari `submit_setoran`
 * (bukan `buat_setoran`) -- momen tunggal row jadi final, biar gak
 * numpuk kalau row sempat direvisi berkali-kali pas draft. Histori
 * buat_setoran/edit_setoran/hapus_setoran yang kejadian SEBELUM ATAU
 * PERSIS PAS submitted_at (revisi draft di Card A) disaring keluar --
 * cuma yang PASCA-submit yang dianggap koreksi (aksi 'edit'/'hapus').
 * buat_pelunasan gak pernah kena filter ini (struktural cuma bisa
 * terjadi setelah submitted). Card A (SetoranEntryController) sudah
 * diretrofit terpisah supaya edit_setoran/hapus_setoran-nya ikut
 * menyimpan setoran_harian_id.
 *
 * CATATAN REVISI (2 September 2026): pendapatan/total_pendapatan/
 * selisih (sekarang selisih_sendiri)/status_keuangan SEKARANG PERSONAL
 * -- live-fetch ke BridgeService::fetchDetailPerUser(), BUKAN lagi
 * dibaca dari snapshot_sopp (loket-wide). Field lama 'selisih' (loket-
 * wide, gabungan semua teller) DIHAPUS TOTAL dari response ini --
 * kalau butuh diverifikasi behavior loket-wide, itu domain
 * SetoranHarian::selisih() sendiri (dipakai Card A/Dashboard/
 * Pelunasan), bukan Detail Setoran lagi. BridgeService di-mock lewat
 * subclass anonim (bind ke container), BUKAN Guzzle MockHandler --
 * override persis di titik yang kita kontrol (fetchDetailPerUser()),
 * gak perlu drama queue/response HTTP palsu.
 */
class DetailSetoranTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->loket = Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Satu']);
        Carbon::setTestNow('2026-08-20 09:00:00');

        // Default SEMUA test: bridge dianggap gagal. Test yang butuh
        // data bridge spesifik WAJIB manggil mockBridgeData() sendiri
        // SEBELUM hit endpoint -- ini cuma jaring pengaman biar test
        // yang gak peduli soal selisih_sendiri/pendapatan gak nunggu
        // network beneran atau nembak ke server asli.
        $this->mockBridgeGagal();
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

    private function buatSuperadminTanpaKapabilitas(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['is_superadmin' => true])->save();

        return $user;
    }

    private function buatVerifikatorLaporanSaja(array $overrides = []): User
    {
        $user = $this->buatTeller($overrides);
        $user->forceFill(['bisa_akses_laporan' => true])->save();

        return $user;
    }

    /**
     * Row SetoranHarian + entries via create() beneran (bukan insert
     * mentah) supaya SetoranDetailObserver ikut kepanggil natural dan
     * entries_updated_at ke-bump apa adanya, sama kayak alur produksi.
     *
     * $snapshotGrandTotal: DIPERTAHANKAN buat kompatibilitas test lain
     * yang gak peduli soal ini (isi kolomnya tetap kesimpen), TAPI
     * SEKARANG TIDAK BERPENGARUH SAMA SEKALI ke pendapatan/selisih_
     * sendiri/status_keuangan di Detail Setoran -- itu semua sekarang
     * murni dari BridgeService (lihat mockBridgeGagal()/mockBridgeData()
     * di bawah).
     */
    private function buatRow(
        User $pemilik,
        string $tanggal,
        string $status,
        ?int $snapshotGrandTotal = 0,
        array $entriesNominal = ['tunai' => 100000],
        ?Loket $loket = null,
    ): SetoranHarian {
        $row = SetoranHarian::create([
            'user_id' => $pemilik->id,
            'loket_id' => ($loket ?? $this->loket)->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
            'snapshot_sopp' => $snapshotGrandTotal === null ? null : [
                'grand_total' => $snapshotGrandTotal,
                'fetched_at' => now()->toIso8601String(),
                'data' => [
                    ['vendor_id' => 1, 'vendor_name' => 'Vendor A', 'kategori' => 'pulsa', 'total' => $snapshotGrandTotal],
                ],
            ],
        ]);

        foreach ($entriesNominal as $metode => $nominal) {
            SetoranDetail::create([
                'setoran_harian_id' => $row->id,
                'metode_pembayaran' => $metode,
                'nominal' => $nominal,
                'foto_bukti_path' => $metode === 'kpay' ? null : 'bukti/x.jpg',
                'nama_karyawan' => $metode === 'kpay' ? 'Budi' : null,
                'identitas_karyawan' => $metode === 'kpay' ? '12345' : null,
                'is_konfirmasi' => true,
            ]);
        }

        return $row->refresh();
    }

    private function fotoValid(): UploadedFile
    {
        return UploadedFile::fake()->image('bukti.jpg');
    }

    /**
     * Bind BridgeService palsu yang SELALU gagal (BridgeException),
     * gak peduli dipanggil berapa kali/dengan parameter apa. Ini
     * default di setUp() -- override lewat mockBridgeData() di test
     * yang butuh angka spesifik.
     */
    private function mockBridgeGagal(): void
    {
        $this->app->instance(BridgeService::class, new class extends BridgeService {
            public function __construct() {}

            public function fetchDetailPerUser(
    string $tanggalMulai,
    string $tanggalSelesai,
    ?int $loketId = null,
    ?User $actor = null
): array {
                throw new BridgeException('Simulasi bridge gagal (test).');
            }
        });
    }

    /**
     * Bind BridgeService palsu yang balikin $rows apa adanya (bentuk
     * mentah sama kayak field asli bridge.php mode detail_rekonsiliasi:
     * tanggal, loket_id, user_id, user_name, vendor_id, vendor_name,
     * produk_id, produk_name, total) -- BUKAN sudah difilter per-user,
     * filtering itu tanggung jawab SelisihPerUserService yang lagi
     * diuji secara gak langsung lewat controller ini.
     */
    private function mockBridgeData(array $rows): void
    {
        $this->app->instance(BridgeService::class, new class($rows) extends BridgeService {
            public function __construct(private readonly array $rows) {}

            public function fetchDetailPerUser(
    string $tanggalMulai,
    string $tanggalSelesai,
    ?int $loketId = null,
    ?User $actor = null
): array {
                return [
                    'data' => $this->rows,
                    'grand_total' => (int) collect($this->rows)->sum('total'),
                ];
            }
        });
    }

    // ===================================================================
    // Sesi
    // ===================================================================

    public function test_show_belum_login_401(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(401);
    }

    public function test_update_belum_login_401(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->putJson("/api/v1/teller/setoran/{$row->id}", [])->assertStatus(401);
    }

    // ===================================================================
    // Otorisasi VIEW (poin 1)
    // ===================================================================

    public function test_show_row_tidak_ada_404(): void
    {
        $teller = $this->buatTeller();

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/999999')->assertStatus(404);
    }

    public function test_show_pemilik_boleh_lihat_submitted(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")->assertOk();
    }

    public function test_show_non_pemilik_tanpa_kapabilitas_ke_submitted_403(): void
    {
        $pemilik = $this->buatTeller();
        $lain = $this->buatTeller();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($lain)->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(403);
    }

    public function test_show_non_pemilik_tanpa_kapabilitas_ke_draft_404_bukan_403(): void
    {
        // Poin 1: draft orang lain -- 404 (gak boleh dilihat = gak
        // pernah ada), bukan 403.
        $pemilik = $this->buatTeller();
        $lain = $this->buatTeller();
        $row = $this->buatRow($pemilik, '2026-08-01', 'draft', 0, []);

        $this->actingAs($lain)->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(404);
    }

    public function test_show_non_pemilik_berkapabilitas_ke_draft_TETAP_404(): void
    {
        // Kapabilitas edit-lintas-loket TIDAK pernah buka akses ke
        // draft orang lain -- 404 sama persis kayak yang gak punya
        // kapabilitas sama sekali.
        $pemilik = $this->buatTeller();
        $verifikator = $this->buatVerifikatorEdit();
        $row = $this->buatRow($pemilik, '2026-08-01', 'draft', 0, []);

        $this->actingAs($verifikator)->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(404);
    }

    public function test_show_pemilik_ke_draft_miliknya_sendiri_TETAP_404(): void
    {
        // FIX: draft berlaku SIAPAPUN, termasuk pemilik sendiri --
        // Detail Setoran cuma pernah beroperasi di row submitted, FE
        // selalu arahin draft (biar punya sendiri) ke Card A. Sebelum
        // fix ini, authorizeAkses() balik duluan buat pemilik sebelum
        // sempat cek status, jadi pemilik bisa nembus draft-nya sendiri
        // lewat endpoint ini -- itu yang dibenerin di sini.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'draft', 0, []);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(404);
    }

    public function test_update_pemilik_ke_draft_miliknya_sendiri_TETAP_404(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'draft', 0, []);

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => null,
            'entries' => [
                ['metode_pembayaran' => 'tunai', 'nominal' => 50000],
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_show_non_pemilik_berkapabilitas_ke_submitted_200(): void
    {
        $pemilik = $this->buatTeller();
        $verifikator = $this->buatVerifikatorEdit();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($verifikator)->getJson("/api/v1/teller/setoran/{$row->id}")->assertOk();
    }

    public function test_show_superadmin_tanpa_kapabilitas_eksplisit_ke_submitted_403(): void
    {
        // Poin 1 (koreksi eksplisit di dokumen): TIDAK ADA bypass
        // is_superadmin -- ability ini dikecualikan dari Gate::before()
        // global, superadmin di-seed semua kapabilitas false.
        $pemilik = $this->buatTeller();
        $superadmin = $this->buatSuperadminTanpaKapabilitas();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($superadmin)->getJson("/api/v1/teller/setoran/{$row->id}")->assertStatus(403);
    }

    // ===================================================================
    // is_editable (poin 1)
    // ===================================================================

    public function test_is_editable_true_untuk_pemilik(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.is_editable', true);
    }

    public function test_is_editable_true_untuk_non_pemilik_berkapabilitas(): void
    {
        $pemilik = $this->buatTeller();
        $verifikator = $this->buatVerifikatorEdit();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($verifikator)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.is_editable', true);
    }

    // ===================================================================
    // Bentuk respons GET -- pendapatan/selisih_sendiri/status_keuangan
    // PERSONAL, sumbernya BridgeService (poin 5, 6, 9, 11, REVISI 2
    // September 2026)
    // ===================================================================

    public function test_show_status_keuangan_gagal_dimuat_kalau_bridge_gagal(): void
    {
        // Ganti dari premis lama (snapshot_sopp null) -- SEKARANG
        // gagal_dimuat murni soal fetch bridge gagal, snapshot_sopp
        // gak lagi relevan buat status ini.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 100000, ['tunai' => 100000]);
        $this->mockBridgeGagal();

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.status_keuangan', 'gagal_dimuat')
            ->assertJsonPath('data.selisih_sendiri', null)
            ->assertJsonPath('data.pendapatan', null)
            ->assertJsonPath('data.total_pendapatan', null);
    }

    public function test_show_data_personal_null_kalau_user_tidak_punya_identitas_legacy(): void
    {
        // Kasus user_id_sopp null (cuma bisa kejadian di superadmin --
        // satu-satunya jalur non-provisioning di sistem ini). Beda dari
        // test di atas: di sini bridge SUKSES (ada datanya), tapi kita
        // gak punya identitas buat dicocokkan -- tetap harus null,
        // BUKAN 0 (0 artinya "cocok", null artinya "gak bisa dihitung").
        $teller = $this->buatTeller();
        $teller->forceFill(['user_id_sopp' => null])->save();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => 'ID_LAIN', 'user_name' => 'Orang Lain',
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 500000],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.status_keuangan', 'gagal_dimuat')
            ->assertJsonPath('data.selisih_sendiri', null)
            ->assertJsonPath('data.pendapatan', null)
            ->assertJsonPath('data.total_pendapatan', null);
    }

    public function test_show_status_keuangan_lunas_kalau_selisih_sendiri_nol(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 100000]);
        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 100000],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.status_keuangan', 'lunas')
            ->assertJsonPath('data.selisih_sendiri', 0);
    }

    public function test_show_status_keuangan_belum_lunas_kalau_ada_selisih_sendiri(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 40000]);
        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 100000],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.status_keuangan', 'belum_lunas')
            ->assertJsonPath('data.selisih_sendiri', 60000);
    }

    public function test_show_selisih_sendiri_TIDAK_gabungan_teller_lain(): void
    {
        // KEBALIKAN dari perilaku lama (selisih() loket-wide, sudah
        // dihapus dari halaman ini). Bridge SELALU balikin SEMUA user
        // sekaligus per loket+tanggal (gak difilter di level bridge),
        // tapi selisih_sendiri milik A gak boleh kebawa data milik B.
        $tellerA = $this->buatTeller(['nama' => 'A']);
        $tellerB = $this->buatTeller(['nama' => 'B']);
        $rowA = $this->buatRow($tellerA, '2026-08-01', 'submitted', 0, ['tunai' => 40000]);
        $this->buatRow($tellerB, '2026-08-01', 'submitted', 0, ['tunai' => 60000], $this->loket);

        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $tellerA->user_id_sopp, 'user_name' => 'A',
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 40000],
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $tellerB->user_id_sopp, 'user_name' => 'B',
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 60000],
        ]);

        $this->actingAs($tellerA)->getJson("/api/v1/teller/setoran/{$rowA->id}")
            ->assertOk()
            ->assertJsonPath('data.selisih_sendiri', 0) // 40000 (punya A doang) - 40000 (entry A)
            ->assertJsonPath('data.total_pendapatan', 40000); // BUKAN 100000 gabungan
    }

    public function test_show_selisih_sendiri_baris_tanggal_lain_tidak_ikut_terhitung(): void
    {
        // Bridge kadang dipanggil dengan rentang tanggal (dari
        // pemanggil lain seperti Riwayat) yang lebih lebar dari 1 hari
        // -- baris yang tanggalnya BUKAN tanggal row ini harus disaring
        // keluar, gak boleh ikut kejumlah.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, []);
        $this->mockBridgeData([
            ['tanggal' => '2026-08-02', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 999999],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.total_pendapatan', 0)
            ->assertJsonPath('data.selisih_sendiri', 0)
            ->assertJsonPath('data.pendapatan', []);
    }

    public function test_show_ringkasan_setoran_tetap_personal_walau_ada_row_lain_di_loket_sama(): void
    {
        // Poin 11 (FINAL): ringkasanSetoran/total_setor TIDAK ikut
        // gabungan loket -- ini gak pernah berubah dari awal, gak
        // bergantung ke bridge sama sekali (murni entries row ini).
        $tellerA = $this->buatTeller(['nama' => 'A']);
        $tellerB = $this->buatTeller(['nama' => 'B']);
        $rowA = $this->buatRow($tellerA, '2026-08-01', 'submitted', 100000, ['tunai' => 40000]);
        $this->buatRow($tellerB, '2026-08-01', 'submitted', 0, ['tunai' => 60000], $this->loket);

        $this->actingAs($tellerA)->getJson("/api/v1/teller/setoran/{$rowA->id}")
            ->assertOk()
            ->assertJsonPath('data.ringkasan_setoran.total_setor', 40000)
            ->assertJsonPath('data.ringkasan_setoran.nominal_tunai', 40000);
    }

    public function test_show_pendapatan_breakdown_personal_pakai_key_kategori(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);
        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 30000],
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 2, 'produk_name' => 'PLN', 'total' => 20000],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.pendapatan.0.vendor_name', 'Vendor A')
            ->assertJsonPath('data.pendapatan.0.kategori', 'Pulsa, PLN')
            ->assertJsonPath('data.pendapatan.0.total', 50000)
            ->assertJsonPath('data.total_pendapatan', 50000)
            ->assertJsonMissingPath('data.pendapatan.0.layanan');
    }

    public function test_show_pendapatan_dipisah_per_vendor(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 80000]);
        $this->mockBridgeData([
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 1, 'vendor_name' => 'Vendor A', 'produk_id' => 1, 'produk_name' => 'Pulsa', 'total' => 30000],
            ['tanggal' => '2026-08-01', 'loket_id' => 1, 'user_id' => $teller->user_id_sopp, 'user_name' => $teller->nama,
                'vendor_id' => 2, 'vendor_name' => 'Vendor B', 'produk_id' => 2, 'produk_name' => 'PLN', 'total' => 50000],
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");
        $response->assertOk()->assertJsonCount(2, 'data.pendapatan');
        $namaVendor = collect($response->json('data.pendapatan'))->pluck('vendor_name');
        $this->assertEqualsCanonicalizing(['Vendor A', 'Vendor B'], $namaVendor->all());
    }

    public function test_show_entries_foto_bukti_url_dibentuk_lewat_endpoint_bukti_setoran(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");

        $response->assertOk();
        $url = $response->json('data.entries.0.foto_bukti_url');
        $this->assertStringContainsString('/api/v1/bukti-setoran/', $url);
    }

    public function test_show_nama_loket_fallback_strip_kalau_loket_terhapus(): void
    {
        $teller = $this->buatTeller();
        $loketSementara = Loket::create(['loket_id' => 99, 'nama_loket' => 'Sementara']);
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1000], $loketSementara);
        $loketSementara->delete();

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.nama_loket', '-');
    }

    public function test_show_dibuat_via_bantuan_pelunasan_ikut_dikirim(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $row->forceFill(['dibuat_via_bantuan_pelunasan' => true])->save();

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.dibuat_via_bantuan_pelunasan', true);
    }

    // ===================================================================
    // petugas[] -- 'input' dari submit_setoran (BUKAN buat_setoran),
    // edit/hapus/pelunasan pasca-submit dari 4 jenis aksi lain.
    // ===================================================================

    public function test_petugas_input_dari_submit_setoran_bukan_buat_setoran(): void
    {
        $teller = $this->buatTeller(['nama' => 'Teller Input']);
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);

        // buat_setoran SENGAJA gak dianggap 'input' -- kalau row ini
        // direvisi berkali-kali pas draft, tiap buat_setoran bakal
        // numpuk jadi banyak baris 'input' palsu. submit_setoran
        // (target_type=setoran_harian, fire sekali doang) yang dipakai.
        ActivityLog::catat($teller, 'submit_setoran', 'setoran_harian', $row->id, [
            'tanggal_transaksi' => '2026-08-01',
            'loket_id' => $this->loket->loket_id,
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.petugas')
            ->assertJsonPath('data.petugas.0.nama', 'Teller Input')
            ->assertJsonPath('data.petugas.0.aksi', 'input');
    }

    public function test_petugas_hapus_setoran_pasca_submit_tetap_muncul_walau_entry_fisik_hilang(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);

        // Waktu dimajuin biar created_at log ini > submitted_at row
        // (harus PASCA-submit supaya lolos filter pra-submit).
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:05:00'));
        ActivityLog::catat($teller, 'hapus_setoran', 'setoran_detail', 999, [
            'setoran_harian_id' => $row->id,
            'entry' => ['metode_pembayaran' => 'tunai', 'nominal' => 20000],
        ]);

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.petugas.0.aksi', 'hapus');
    }

    public function test_petugas_edit_hapus_setoran_pra_submit_disaring_keluar(): void
    {
        // Histori "lagi ngisi form" (created_at <= submitted_at) BUKAN
        // koreksi pasca-final -- disaring, walau jenis_aksi-nya sama
        // (edit_setoran/hapus_setoran) dan walau field
        // setoran_harian_id ada (Card A udah diretrofit).
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);

        ActivityLog::catat($teller, 'edit_setoran', 'setoran_detail', 1, [
            'setoran_harian_id' => $row->id,
            'sebelum' => ['nominal' => 1000],
            'sesudah' => ['nominal' => 2000],
        ]);
        ActivityLog::catat($teller, 'hapus_setoran', 'setoran_detail', 2, [
            'setoran_harian_id' => $row->id,
            'entry' => ['metode_pembayaran' => 'tunai', 'nominal' => 5000],
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");
        $aksi = collect($response->json('data.petugas'))->pluck('aksi');
        $this->assertNotContains('edit', $aksi);
        $this->assertNotContains('hapus', $aksi);
    }

    public function test_petugas_buat_pelunasan_ikut_muncul_walau_pas_submit(): void
    {
        // buat_pelunasan TIDAK PERNAH kena filter pra-submit (struktural
        // cuma bisa terjadi setelah submitted) -- beda dari 3 jenis aksi
        // lain, jadi gak perlu majuin waktu buat test ini.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);

        ActivityLog::catat($teller, 'buat_pelunasan', 'setoran_detail', 12345, [
            'setoran_harian_id' => $row->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 30000,
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");

        $aksi = collect($response->json('data.petugas'))->pluck('aksi');
        $this->assertContains('pelunasan', $aksi);
    }

    public function test_petugas_tidak_nyampur_log_row_lain(): void
    {
        $teller = $this->buatTeller();
        $rowSaya = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);
        $rowLain = $this->buatRow($teller, '2026-08-02', 'submitted', 0, ['tunai' => 1]);

        ActivityLog::catat($teller, 'buat_pelunasan', 'setoran_detail', 1, [
            'setoran_harian_id' => $rowLain->id,
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$rowSaya->id}");
        $aksi = collect($response->json('data.petugas'))->pluck('aksi');
        $this->assertNotContains('pelunasan', $aksi);
    }

    public function test_petugas_log_tanpa_setoran_harian_id_tidak_pernah_kedeteksi(): void
    {
        // Query petugas() nyari lewat detail_perubahan->setoran_harian_id
        // -- log manapun yang gak punya key itu (dari sumber manapun,
        // kapanpun) gak akan pernah ke-match, apapun jenis_aksi-nya.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:05:00'));

        ActivityLog::catat($teller, 'edit_setoran', 'setoran_detail', 1, [
            'sebelum' => ['nominal' => 1000],
            'sesudah' => ['nominal' => 2000],
        ]);

        $response = $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}");
        $aksi = collect($response->json('data.petugas'))->pluck('aksi');
        $this->assertNotContains('edit', $aksi);
    }

    public function test_show_nama_teller_ikut_dikirim(): void
    {
        $teller = $this->buatTeller(['nama' => 'Budi Setiawan']);
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.nama_teller', 'Budi Setiawan');
    }

    public function test_show_status_workflow_field_name(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $this->actingAs($teller)->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()->assertJsonPath('data.status_workflow', 'submitted');
    }

    // ===================================================================
    // PUT -- otorisasi
    // Owner boleh EDIT miliknya sendiri.
    // Non-owner wajib punya bisa_edit_setoran_semua_loket.
    // bisa_akses_laporan saja hanya VIEW, bukan EDIT.
    // ===================================================================

    public function test_update_non_pemilik_tanpa_kapabilitas_403(): void
    {
        $pemilik = $this->buatTeller();
        $lain = $this->buatTeller();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $this->actingAs($lain)
            ->putJson("/api/v1/teller/setoran/{$row->id}", [
                'entries_updated_at' => $row->entries_updated_at?->toIso8601String(),
                'entries' => [
                    [
                        'id' => $entry->id,
                        'metode_pembayaran' => 'tunai',
                        'nominal' => 100000,
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_update_superadmin_tanpa_kapabilitas_ke_submitted_403(): void
    {
        $pemilik = $this->buatTeller();
        $superadmin = $this->buatSuperadminTanpaKapabilitas();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $this->actingAs($superadmin)
            ->putJson("/api/v1/teller/setoran/{$row->id}", [
                'entries_updated_at' => $row->entries_updated_at?->toIso8601String(),
                'entries' => [
                    [
                        'id' => $entry->id,
                        'metode_pembayaran' => 'tunai',
                        'nominal' => 100000,
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_update_non_pemilik_ke_draft_404(): void
    {
        $pemilik = $this->buatTeller();
        $verifikator = $this->buatVerifikatorEdit();
        $row = $this->buatRow($pemilik, '2026-08-01', 'draft', 0, []);

        $response = $this->actingAs($verifikator)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => null,
            'entries' => [],
        ]);

        $response->assertStatus(404);
    }

    // ===================================================================
    // PUT -- validasi payload dasar (poin 2)
    // ===================================================================

    public function test_update_minimal_1_entry_wajib(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at?->toIso8601String(),
            'entries' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_entries_updated_at_wajib_ada_di_payload(): void
    {
        // Rule 'present' -- field harus ADA, walau boleh null isinya.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries' => [
                ['metode_pembayaran' => 'tunai', 'nominal' => 1000, 'foto_bukti' => $this->fotoValid()],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['entries_updated_at']);
    }

    public function test_update_id_dikirim_dua_kali_422(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 1000],
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 2000],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_id_bukan_milik_row_ini_422(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $rowLain = $this->buatRow($teller, '2026-08-02', 'submitted');
        $entryRowLain = $rowLain->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entryRowLain->id, 'metode_pembayaran' => 'tunai', 'nominal' => 1000],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_entry_baru_non_kpay_wajib_foto(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['metode_pembayaran' => 'transfer', 'nominal' => 5000],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['entries.0.foto_bukti']);
    }

    public function test_update_entry_kpay_tidak_boleh_ada_foto(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                [
                    'metode_pembayaran' => 'kpay',
                    'nominal' => 5000,
                    'nama_karyawan' => 'Budi',
                    'identitas_karyawan' => '1',
                    'foto_bukti' => $this->fotoValid(),
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['entries.0.foto_bukti']);
    }

    public function test_update_entry_kpay_wajib_nama_dan_identitas_karyawan(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 5000],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.nama_karyawan', 'entries.0.identitas_karyawan']);
    }

    // ===================================================================
    // PUT -- upsert + delete-by-omission (poin 2)
    // ===================================================================

    public function test_update_entry_baru_tercipta_dan_ter_log_buat_setoran(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entryLama = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entryLama->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000],
                ['metode_pembayaran' => 'transfer', 'nominal' => 20000, 'foto_bukti' => $this->fotoValid()],
            ],
        ]);

        $response->assertOk()->assertJsonCount(2, 'data.entries');
        $this->assertDatabaseHas('setoran_detail', [
            'setoran_harian_id' => $row->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 20000,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'jenis_aksi' => 'buat_setoran',
        ]);
    }

    public function test_update_entry_lama_diedit_dan_ter_log_sebelum_sesudah(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 75000],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.entries.0.nominal', 75000);

        $log = ActivityLog::where('jenis_aksi', 'edit_setoran')->first();
        $this->assertNotNull($log);
        $this->assertEquals($row->id, $log->detail_perubahan['setoran_harian_id']);
        $this->assertEquals(50000, $log->detail_perubahan['sebelum']['nominal']);
        $this->assertEquals(75000, $log->detail_perubahan['sesudah']['nominal']);
    }

    public function test_update_entry_yang_tidak_dikirim_ulang_hard_delete(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);
        $entryLama = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['metode_pembayaran' => 'transfer', 'nominal' => 30000, 'foto_bukti' => $this->fotoValid()],
            ],
        ]);

        $response->assertOk();
        // Poin 2: hard delete, BUKAN soft-delete -- row-nya harus
        // beneran hilang dari tabel, bukan cuma ke-flag deleted_at.
        $this->assertDatabaseMissing('setoran_detail', ['id' => $entryLama->id]);
        $this->assertDatabaseHas('activity_logs', [
            'jenis_aksi' => 'hapus_setoran',
            'target_id' => $entryLama->id,
        ]);
    }

    public function test_update_hapus_foto_fisik_ditunda_sampai_afterCommit(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        Storage::disk('local')->put('bukti_setoran/bukti/x.jpg', 'isi');
        $entryLama = $row->entries->first();

        $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['metode_pembayaran' => 'transfer', 'nominal' => 30000, 'foto_bukti' => $this->fotoValid()],
            ],
        ])->assertOk();

        Storage::disk('local')->assertMissing('bukti_setoran/bukti/x.jpg');
    }

    // ===================================================================
    // PUT -- transisi kpay (niru pola Card A, poin 2)
    // ===================================================================

    public function test_update_pindah_ke_kpay_hapus_foto_lama_dan_path_null(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                [
                    'id' => $entry->id,
                    'metode_pembayaran' => 'kpay',
                    'nominal' => 50000,
                    'nama_karyawan' => 'Budi',
                    'identitas_karyawan' => '999',
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.entries.0.foto_bukti_url', null);
        $this->assertDatabaseHas('setoran_detail', ['id' => $entry->id, 'foto_bukti_path' => null]);
    }

    public function test_update_pindah_dari_kpay_wajib_foto_baru(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['kpay' => 50000]);
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 50000],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['entries.0.foto_bukti']);
    }

    public function test_update_pindah_dari_kpay_dengan_foto_baru_berhasil(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['kpay' => 50000]);
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 50000, 'foto_bukti' => $this->fotoValid()],
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.entries.0.foto_bukti_url'));
    }

    public function test_update_baris_lama_bukan_kpay_tidak_kirim_foto_pertahankan_lama(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 50000]);
        $entry = $row->entries->first();
        $fotoPathLama = $entry->foto_bukti_path;

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 60000],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_detail', ['id' => $entry->id, 'foto_bukti_path' => $fotoPathLama]);
    }

    // ===================================================================
    // PUT -- rollback file baru kalau ada entry lain di batch gagal
    // ===================================================================

    public function test_update_rollback_file_baru_kalau_batch_gagal_di_tengah(): void
    {
        $teller = $this->buatTeller();
        $rowLain = $this->buatRow($teller, '2026-08-02', 'submitted');
        $entryMilikRowLain = $rowLain->entries->first();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted', 0, ['tunai' => 1]);

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                // Baris pertama valid & bakal nulis file baru DULU...
                ['metode_pembayaran' => 'transfer', 'nominal' => 10000, 'foto_bukti' => $this->fotoValid()],
                // ...baris kedua invalid (id bukan milik row ini) ->
                // abort 422 di tengah loop, SETELAH file baris pertama
                // sempat ditulis.
                ['id' => $entryMilikRowLain->id, 'metode_pembayaran' => 'tunai', 'nominal' => 5000],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('setoran_detail', [
            'setoran_harian_id' => $row->id,
            'metode_pembayaran' => 'transfer',
            'nominal' => 10000,
        ]);
        // File yang keburu ditulis buat baris pertama harus ikut
        // dibersihkan -- gak boleh nyisa orphan di disk.
        $storedFiles = Storage::disk('local')->allFiles('bukti_setoran/2026-08-01');
        $this->assertCount(0, $storedFiles);
    }

    // ===================================================================
    // Optimistic lock -- entries_updated_at (poin 4)
    // ===================================================================

    public function test_update_entries_updated_at_mismatch_409(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => now()->subMinutes(5)->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000],
            ],
        ]);

        $response->assertStatus(409);
    }

    public function test_update_entries_updated_at_cocok_berhasil_dan_ke_bump(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();
        $sebelumBump = $row->entries_updated_at;

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00'));

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $sebelumBump->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 120000],
            ],
        ]);

        $response->assertOk();
        $this->assertNotEquals(
            $sebelumBump->toIso8601String(),
            $response->json('data.entries_updated_at'),
        );
    }

    public function test_update_entries_updated_at_null_di_db_skip_cek(): void
    {
        // Skenario defensif poin 4 -- entries_updated_at dipaksa null
        // manual (bypass observer) buat simulasikan row yang belum
        // pernah tersentuh sama sekali. FE echo balik null, harus
        // tetap diterima.
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();
        SetoranHarian::where('id', $row->id)->update(['entries_updated_at' => null]);

        $response = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => null,
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 999],
            ],
        ]);

        $response->assertOk();
    }

    // ===================================================================
    // Debounce PUT (double-klik, niru Card A)
    // ===================================================================

    public function test_update_double_klik_beruntun_kena_429(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $payload = [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000],
            ],
        ];

        $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", $payload);
        $kedua = $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", $payload);

        $kedua->assertStatus(429);
    }

    // ===================================================================
    // Immutability tanggal_transaksi / loket_id (poin 2)
    // ===================================================================

    public function test_update_tidak_ada_field_untuk_ubah_tanggal_atau_loket(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRow($teller, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $this->actingAs($teller)->putJson("/api/v1/teller/setoran/{$row->id}", [
            'entries_updated_at' => $row->entries_updated_at->toIso8601String(),
            'tanggal_transaksi' => '2026-09-01',
            'loket_id' => 999,
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'tunai', 'nominal' => 100000],
            ],
        ])->assertOk();

        $row->refresh();
        $this->assertEquals('2026-08-01', $row->tanggal_transaksi->toDateString());
        $this->assertEquals($this->loket->loket_id, $row->loket_id);
    }

    public function test_show_bisa_akses_laporan_saja_ke_submitted_orang_lain_KINI_BOLEH(): void
    {
        // Keputusan dibalik (Kontrak Laporan §5) -- bisa_akses_laporan
        // sekarang boleh VIEW karena Laporan butuh navigasi read-only.
        // EDIT tetap 403, lihat test di bawah.
        $pemilik = $this->buatTeller();
        $verifikatorLaporan = $this->buatVerifikatorLaporanSaja();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($verifikatorLaporan)->getJson("/api/v1/teller/setoran/{$row->id}")->assertOk();
    }

    public function test_update_bisa_akses_laporan_saja_ke_submitted_orang_lain_TETAP_403(): void
    {
        // bisa_akses_laporan hanya memberi hak VIEW.
        // Non-owner tetap tidak boleh EDIT tanpa
        // bisa_edit_setoran_semua_loket.
        $pemilik = $this->buatTeller();
        $verifikatorLaporan = $this->buatVerifikatorLaporanSaja();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');
        $entry = $row->entries->first();

        $this->actingAs($verifikatorLaporan)
            ->putJson("/api/v1/teller/setoran/{$row->id}", [
                'entries_updated_at' => $row->entries_updated_at?->toIso8601String(),
                'entries' => [
                    [
                        'id' => $entry->id,
                        'metode_pembayaran' => 'tunai',
                        'nominal' => 100000,
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_show_bisa_akses_laporan_saja_is_editable_false(): void
    {
        $pemilik = $this->buatTeller();
        $verifikatorLaporan = $this->buatVerifikatorLaporanSaja();
        $row = $this->buatRow($pemilik, '2026-08-01', 'submitted');

        $this->actingAs($verifikatorLaporan)
            ->getJson("/api/v1/teller/setoran/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', false);
    }
}
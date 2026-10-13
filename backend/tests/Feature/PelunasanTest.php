<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use App\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PelunasanTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20');
        $this->loket = Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Satu', 'synced_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['loket_id' => $this->loket->loket_id], $overrides));
    }

    private function buatRowSubmitted(int $loketId, string $tanggal, int $pendapatanSopp, int $totalSetorAwal, ?User $pemilik = null): SetoranHarian
    {
        $pemilik ??= User::factory()->create(['loket_id' => $loketId]);
        $row = SetoranHarian::create([
            'user_id' => $pemilik->id,
            'loket_id' => $loketId,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'submitted_at' => now(),
            'snapshot_sopp' => ['data' => [], 'grand_total' => $pendapatanSopp, 'fetched_at' => $tanggal . 'T10:00:00+07:00'],
        ]);
        if ($totalSetorAwal > 0) {
            SetoranDetail::create([
                'setoran_harian_id' => $row->id,
                'metode_pembayaran' => 'tunai',
                'nominal' => $totalSetorAwal,
                'foto_bukti_path' => 'bukti/awal.jpg',
                'is_konfirmasi' => true,
            ]);
        }

        return $row;
    }

    private function mockBridgeRefresh(int $pendapatanBaru): void
    {
        $this->mock(SnapshotService::class, function ($mock) use ($pendapatanBaru) {
            $mock->shouldReceive('getForLoketTanggal')
                ->withArgs(fn($loketId, $tanggal, $forceRefresh, $user) => $forceRefresh === true)
                ->andReturn([
                    'data' => [],
                    'grand_total' => $pendapatanBaru,
                    'fetched_at' => now()->toDateTimeString(),
                    'data_terkini' => true,
                ]);
        });
    }

    public function test_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/teller/setoran/pelunasan')->assertStatus(401);
    }

    public function test_user_tanpa_loket_ditolak_403(): void
    {
        $teller = $this->buatTeller(['loket_id' => null]);
        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan')->assertStatus(403);
    }

    public function test_store_user_tanpa_loket_ditolak_403(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller(['loket_id' => null]);
        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);
        $response->assertStatus(403);
    }

    public function test_lihat_loket_lain_lewat_query_param_butuh_kapabilitas(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $this->buatRowSubmitted($loketLain->loket_id, '2026-08-18', 500000, 300000);

        $tanpaKapabilitas = $this->buatTeller();
        $this->actingAs($tanpaKapabilitas)
            ->getJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id)
            ->assertStatus(403);

        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);
        $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.tanggal_tersedia');
    }

    public function test_muat_data_loket_lain_lewat_query_param_butuh_kapabilitas(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $this->buatRowSubmitted($loketLain->loket_id, '2026-08-18', 500000, 300000);
        $this->mockBridgeRefresh(500000);

        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);
        $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id . '&tanggal=2026-08-18')
            ->assertOk();
    }

    public function test_daftar_tanggal_cuma_yang_submitted_dan_ada_selisih(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-17', 500000, 500000);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(1, 'data.tanggal_tersedia')
            ->assertJsonPath('data.tanggal_tersedia.0.tanggal', '2026-08-18');
    }

    public function test_daftar_tanggal_tanpa_snapshot_TETAP_muncul(): void
    {
        $teller = $this->buatTeller();
        $pemilik = User::factory()->create(['loket_id' => $this->loket->loket_id]);
        SetoranHarian::create([
            'user_id' => $pemilik->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-18',
            'status' => 'submitted',
            'submitted_at' => now(),
            'snapshot_sopp' => null,
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(1, 'data.tanggal_tersedia')
            ->assertJsonPath('data.tanggal_tersedia.0.tanggal', '2026-08-18');
    }

    public function test_daftar_tanggal_draft_tidak_ikut(): void
    {
        $teller = $this->buatTeller();
        $pemilik = User::factory()->create(['loket_id' => $this->loket->loket_id]);
        SetoranHarian::create([
            'user_id' => $pemilik->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-18',
            'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 500000, 'fetched_at' => now()->toDateTimeString()],
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(0, 'data.tanggal_tersedia');
    }

    public function test_daftar_tanggal_hari_ini_TETAP_muncul_kalau_ada_selisih(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-20', 500000, 300000);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(1, 'data.tanggal_tersedia')
            ->assertJsonPath('data.tanggal_tersedia.0.tanggal', '2026-08-20');
    }

    public function test_daftar_tanggal_loket_lain_tidak_ikut_kehitung(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($loketLain->loket_id, '2026-08-18', 500000, 300000);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(0, 'data.tanggal_tersedia');
    }

    public function test_daftar_tanggal_gak_manggil_bridge_sama_sekali(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldNotReceive('getForLoketTanggal');
        });

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan')->assertOk();
    }

    public function test_muat_data_force_refresh_bridge(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);
        $this->mockBridgeRefresh(600000);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18');

        $response->assertOk()->assertJsonPath('data.tanggal_transaksi', '2026-08-18');
    }

    public function test_muat_data_tanggal_belum_submitted_ditolak_422(): void
    {
        $teller = $this->buatTeller();
        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18');

        $response->assertStatus(422);
    }

    public function test_muat_data_rate_limit(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);
        $this->mockBridgeRefresh(500000);

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18')->assertOk();
        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18')->assertStatus(429);
    }

    public function test_muat_data_bridge_gagal_tapi_ada_snapshot_lama_tetap_fallback(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);

        $this->mock(SnapshotService::class, function ($mock) use ($row) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'data' => [],
                'grand_total' => 500000,
                'fetched_at' => $row->snapshot_sopp['fetched_at'],
                'data_terkini' => false,
            ]);
        });

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18');

        $response->assertOk()
            ->assertJsonPath('data.data_terkini', false)
            ->assertJsonPath('data.sisa_selisih', 200000);
    }

    public function test_muat_data_bridge_gagal_dikembalikan_502(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000);
        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andThrow(new \App\Exceptions\BridgeException('gagal'));
        });

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18')->assertStatus(502);
    }

    public function test_muat_data_tanggal_hari_ini_SEKARANG_boleh(): void
    {
        $teller = $this->buatTeller();
        $this->buatRowSubmitted($this->loket->loket_id, '2026-08-20', 500000, 300000);
        $this->mockBridgeRefresh(500000);

        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-20')->assertOk();
    }

    public function test_submit_sukses_satu_metode(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.selisih', 0)
            ->assertJsonPath('data.status_keuangan', 'lunas');
        $this->assertDatabaseHas('setoran_detail', ['setoran_harian_id' => $row->id, 'nominal' => 200000]);
        $this->assertTrue(
            ActivityLog::where('jenis_aksi', 'buat_pelunasan')
                ->where('detail_perubahan->setoran_harian_id', $row->id)->exists()
        );
    }

    public function test_submit_sukses_multi_metode(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 200000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 150000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
                ['metode' => 'transfer', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
                ['metode' => 'kpay', 'nominal' => 50000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '12345'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.selisih', 0);
        $this->assertSame(4, SetoranDetail::where('setoran_harian_id', $row->id)->count());
        $this->assertSame(3, ActivityLog::where('jenis_aksi', 'buat_pelunasan')
            ->where('detail_perubahan->setoran_harian_id', $row->id)->count());
    }

    public function test_submit_partial_diperbolehkan(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 100000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 150000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.selisih', 250000)
            ->assertJsonPath('data.status_keuangan', 'belum_lunas');
    }

    public function test_submit_total_melebihi_sisa_selisih_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 300000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, SetoranDetail::where('setoran_harian_id', $row->id)->count());
    }

    public function test_submit_metode_duplikat_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 100000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_submit_lebih_dari_4_baris_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 0, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 1000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
                ['metode' => 'transfer', 'nominal' => 1000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
                ['metode' => 'qris', 'nominal' => 1000, 'bukti' => UploadedFile::fake()->image('c.jpg')],
                ['metode' => 'kpay', 'nominal' => 1000, 'nama_karyawan' => 'A', 'identitas_karyawan' => '1'],
                ['metode' => 'tunai', 'nominal' => 1000, 'bukti' => UploadedFile::fake()->image('e.jpg')],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_submit_row_belum_submitted_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-18',
            'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 500000, 'fetched_at' => now()->toDateTimeString()],
        ]);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_submit_kedua_kali_di_tanggal_yang_sama_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 100000, $teller);

        $payload = [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ];

        $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', $payload)->assertOk();
        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'transfer', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
            ],
        ]);

        $response->assertStatus(422);
    }

    // test_submit_id_tidak_ada_404 DIHAPUS (28 Agustus 2026): store()
    // gak nerima setoran_harian_id lagi sama sekali, jadi skenario "ID
    // rujukan gak ada" gak punya padanan input di API baru. Kasus
    // "tanggal gak eligible" sekarang balik 422 lewat
    // cariAtauBuatRowAktor(), bukan 404 -- sudah dicover test lain
    // (test_muat_data_tanggal_belum_submitted_ditolak_422 utk GET, dan
    // test_submit_ke_tanggal_yang_row_nya_sudah_disoftdelete_ditolak_422
    // di bawah utk POST).

    public function test_teller_lain_di_loket_sama_boleh_pelunasan_tanpa_gate(): void
    {
        Storage::fake('local');
        $pemilik = User::factory()->create(['loket_id' => $this->loket->loket_id]);
        $tellerLain = User::factory()->create(['loket_id' => $this->loket->loket_id]);
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $pemilik);

        $response = $this->actingAs($tellerLain)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertOk();
    }

    public function test_loket_lain_tanpa_kapabilitas_ditolak_403(): void
    {
        Storage::fake('local');
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $pemilik = User::factory()->create(['loket_id' => $loketLain->loket_id]);
        $row = $this->buatRowSubmitted($loketLain->loket_id, '2026-08-18', 500000, 300000, $pemilik);

        $tellerLoketBeda = $this->buatTeller();

        // resolveLoketId() sekarang JUGA dipakai di store() (sama
        // persis pola GET) -- loket target HARUS eksplisit lewat
        // ?loket_id=, gak lagi diturunin dari row rujukan kayak
        // sebelumnya. Tanpa loket_id di query, request ini bakal
        // ke-resolve ke loket SENDIRI $tellerLoketBeda (yang gak
        // eligible), bukan ke-otorisasi ke loketLain.
        $response = $this->actingAs($tellerLoketBeda)->postJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id, [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_loket_lain_dengan_kapabilitas_boleh(): void
    {
        Storage::fake('local');
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $pemilik = User::factory()->create(['loket_id' => $loketLain->loket_id]);
        $row = $this->buatRowSubmitted($loketLain->loket_id, '2026-08-18', 500000, 300000, $pemilik);

        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);

        $response = $this->actingAs($verifikator)->postJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id, [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertOk();
    }

    public function test_kpay_wajib_nama_dan_identitas_karyawan_tanpa_foto(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'kpay', 'nominal' => 200000],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'metode_pembayaran.0.nama_karyawan',
            'metode_pembayaran.0.identitas_karyawan',
        ]);
    }

    public function test_non_kpay_wajib_foto_bukti(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metode_pembayaran.0.bukti']);
    }

    public function test_dua_teller_beda_di_loket_sama_boleh_pelunasan_tanggal_sama_masing_masing(): void
    {
        // Rewrite 27 Agustus ("no numpang"): entry tellerDua NEMPEL KE
        // ROW MILIK DIA SENDIRI (auto-create), BUKAN ke row $row milik
        // tellerSatu -- beda dari desain lama yang numpang ke 1 row
        // kanonik. Makanya assert log-nya dipisah per row, bukan
        // digabung ke $row->id doang.
        Storage::fake('local');
        $tellerSatu = $this->buatTeller();
        $tellerDua = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 100000, $tellerSatu);

        $responseSatu = $this->actingAs($tellerSatu)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);
        $responseSatu->assertOk()->assertJsonPath('data.selisih', 200000);

        // tellerDua kirim {loket_id (default: sendiri), tanggal} yang
        // sama -- gak ada lagi ID row yang dirujuk. cariAtauBuatRowAktor()
        // yang nyari/bikin row MILIK tellerDua sendiri (auto-create),
        // id-nya beda dari $row->id (row tellerSatu).
        $responseDua = $this->actingAs($tellerDua)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'transfer', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
            ],
        ]);
        $responseDua->assertOk()->assertJsonPath('data.selisih', 0)->assertJsonPath('data.status_keuangan', 'lunas');

        $rowIdTellerDua = $responseDua->json('data.setoran_harian_id');
        $this->assertNotSame($row->id, $rowIdTellerDua, 'tellerDua harus dapet row sendiri, bukan numpang ke row tellerSatu');

        $this->assertSame(1, ActivityLog::where('jenis_aksi', 'buat_pelunasan')
            ->where('user_id', $tellerSatu->id)
            ->where('detail_perubahan->setoran_harian_id', $row->id)->count());
        $this->assertSame(1, ActivityLog::where('jenis_aksi', 'buat_pelunasan')
            ->where('user_id', $tellerDua->id)
            ->where('detail_perubahan->setoran_harian_id', $rowIdTellerDua)->count());
    }

    public function test_teller_yang_sama_tetap_ditolak_walau_teller_lain_sudah_pernah(): void
    {
        Storage::fake('local');
        $tellerSatu = $this->buatTeller();
        $tellerDua = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 0, $tellerSatu);

        $this->actingAs($tellerSatu)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ])->assertOk();

        $this->actingAs($tellerDua)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'transfer', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('b.jpg')],
            ],
        ])->assertOk();

        $response = $this->actingAs($tellerSatu)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'qris', 'nominal' => 50000, 'bukti' => UploadedFile::fake()->image('c.jpg')],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_submit_ditolak_kalau_snapshot_belum_pernah_dimuat(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-18',
            'status' => 'submitted',
            'submitted_at' => now(),
            'snapshot_sopp' => null,
        ]);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SetoranDetail::where('setoran_harian_id', $row->id)->count());
    }

    public function test_submit_total_persis_sama_dengan_sisa_selisih_diperbolehkan(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.selisih', 0)->assertJsonPath('data.status_keuangan', 'lunas');
    }

    public function test_submit_bukti_mime_tidak_valid_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->create('bukti.pdf', 100)],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metode_pembayaran.0.bukti']);
    }

    public function test_submit_nominal_nol_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 0, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metode_pembayaran.0.nominal']);
    }

    public function test_submit_metode_pembayaran_kosong_ditolak(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metode_pembayaran']);
    }

    public function test_row_yang_disoftdelete_tidak_muncul_di_daftar_tanggal(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);
        $row->delete();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan');

        $response->assertOk()->assertJsonCount(0, 'data.tanggal_tersedia');
    }

    public function test_loket_id_tidak_terdaftar_ditolak_422_bukan_403(): void
    {
        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);

        $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/pelunasan?loket_id=999999')
            ->assertStatus(422);
    }

    public function test_store_loket_id_tidak_terdaftar_ditolak_422_bukan_403(): void
    {
        // Simetri dengan test GET di atas -- store() sekarang manggil
        // resolveLoketId() di titik paling awal, persis kayak index(),
        // jadi guard 422-sebelum-403 buat loket_id ngasal harusnya
        // berlaku sama di kedua endpoint.
        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);

        $this->actingAs($verifikator)
            ->postJson('/api/v1/teller/setoran/pelunasan?loket_id=999999', [
                'tanggal' => '2026-08-18',
                'metode_pembayaran' => [
                    ['metode' => 'kpay', 'nominal' => 100000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_soft_delete_setoran_harian_tidak_ikut_hapus_entries(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $row->delete();

        $this->assertNull(SetoranHarian::find($row->id));
        $this->assertDatabaseHas('setoran_harian', ['id' => $row->id]);
        $this->assertDatabaseHas('setoran_detail', ['setoran_harian_id' => $row->id]);
    }

    public function test_muat_data_row_yang_disoftdelete_ditolak_422(): void
    {
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);
        $row->delete();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=2026-08-18');

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Row kanonik & percabangan mode sendiri/lintas-loket -- KEDUANYA
    // udah gak ada lagi (rewrite 28 Agustus 2026): index() satu jalur
    // buat siapapun lewat resolveLoketId(), daftarTanggalTersedia() gak
    // pernah nyertain nama_teller/setoran_harian_id, entah loket sendiri
    // atau ?loket_id= ke loket lain. Pakai loket dari setUp()
    // ($this->loket, id 1), JANGAN bikin loket baru id 1 lagi di sini
    // (sudah ada, bakal duplicate-key).
    // -----------------------------------------------------------------

    public function test_daftar_tanggal_tidak_pernah_menyertakan_setoran_harian_id_atau_nama_teller(): void
    {
        $tellerA = $this->buatTeller();
        $tellerB = $this->buatTeller();
        $tanggal = now()->toDateString();
        $snapshot = ['grand_total' => 100000, 'data' => [], 'fetched_at' => now()->toIso8601String()];

        SetoranHarian::create([
            'user_id' => $tellerB->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'snapshot_sopp' => $snapshot,
            'submitted_at' => now()->subMinute(),
        ]);

        $daftar = $this->actingAs($tellerA)->getJson('/api/v1/teller/setoran/pelunasan');
        $daftar->assertOk()
            ->assertJsonPath('data.tanggal_tersedia.0.tanggal', $tanggal)
            ->assertJsonMissingPath('data.tanggal_tersedia.0.setoran_harian_id')
            ->assertJsonMissingPath('data.tanggal_tersedia.0.nama_teller');
    }

    public function test_daftar_tanggal_lintas_loket_juga_dedup_dan_tanpa_nama_teller(): void
    {
        // Ini kebalikan dari perilaku LAMA yang sengaja TIDAK dedup di
        // mode lintas loket (per teller per tanggal, ada nama_teller).
        // Setelah percabangan modenya dihapus total, capability holder
        // yang intip loket lain HARUS dapet daftar yang identik
        // bentuknya sama kayak loket sendiri -- gak peduli ada berapa
        // teller yang submit di tanggal itu.
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $tellerSatu = User::factory()->create(['loket_id' => $loketLain->loket_id]);
        $tellerDua = User::factory()->create(['loket_id' => $loketLain->loket_id]);
        $tanggal = '2026-08-18';
        $snapshot = ['grand_total' => 500000, 'data' => [], 'fetched_at' => now()->toIso8601String()];

        SetoranHarian::create([
            'user_id' => $tellerSatu->id, 'loket_id' => $loketLain->loket_id,
            'tanggal_transaksi' => $tanggal, 'status' => 'submitted',
            'snapshot_sopp' => $snapshot, 'submitted_at' => now()->subMinute(),
        ]);
        SetoranHarian::create([
            'user_id' => $tellerDua->id, 'loket_id' => $loketLain->loket_id,
            'tanggal_transaksi' => $tanggal, 'status' => 'submitted',
            'snapshot_sopp' => $snapshot, 'submitted_at' => now(),
        ]);

        $verifikator = $this->buatTeller(['bisa_input_setoran_semua_loket' => true]);
        $daftar = $this->actingAs($verifikator)
            ->getJson('/api/v1/teller/setoran/pelunasan?loket_id=' . $loketLain->loket_id);

        $daftar->assertOk()
            ->assertJsonCount(1, 'data.tanggal_tersedia')
            ->assertJsonPath('data.tanggal_tersedia.0.tanggal', $tanggal)
            ->assertJsonMissingPath('data.tanggal_tersedia.0.setoran_harian_id')
            ->assertJsonMissingPath('data.tanggal_tersedia.0.nama_teller');
    }

    public function test_tie_break_pakai_id_kalau_submitted_at_kembar_persis(): void
    {
        $tellerA = $this->buatTeller();
        $tellerB = $this->buatTeller();
        $tanggal = now()->toDateString();
        $snapshot = ['grand_total' => 100000, 'data' => [], 'fetched_at' => now()->toIso8601String()];
        $waktuSama = now();

        $rowA = SetoranHarian::create([
            'user_id' => $tellerA->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'snapshot_sopp' => $snapshot,
            'submitted_at' => $waktuSama,
        ]);
        $rowB = SetoranHarian::create([
            'user_id' => $tellerB->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'snapshot_sopp' => $snapshot,
            'submitted_at' => $waktuSama,
        ]);

        $muat = $this->actingAs($tellerA)->getJson('/api/v1/teller/setoran/pelunasan?tanggal=' . $tanggal);
        $muat->assertOk();

        $this->assertSame($rowA->id, $muat->json('data.setoran_harian_id'));
    }

    public function test_pelunasan_non_kpay_kirim_nama_karyawan_ditolak_422(): void
    {
        $user = $this->buatTeller();

        $harian = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'snapshot_sopp' => ['grand_total' => 100000, 'data' => [], 'fetched_at' => now()->toIso8601String()],
        ]);

        $foto = UploadedFile::fake()->image('bukti.jpg');
        $r = $this->actingAs($user)->post('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => now()->toDateString(),
            'metode_pembayaran' => [[
                'metode' => 'tunai',
                'nominal' => 50000,
                'bukti' => $foto,
                'nama_karyawan' => 'Nyasar Dari Kpay',
                'identitas_karyawan' => '999888777',
            ]],
        ]);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors([
            'metode_pembayaran.0.nama_karyawan',
            'metode_pembayaran.0.identitas_karyawan',
        ]);
        $this->assertSame(0, SetoranDetail::count());
    }

    public function test_pelunasan_kpay_tetap_tersimpan_nama_karyawan(): void
    {
        $user = $this->buatTeller();

        $harian = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'snapshot_sopp' => ['grand_total' => 100000, 'data' => [], 'fetched_at' => now()->toIso8601String()],
        ]);

        $r = $this->actingAs($user)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => now()->toDateString(),
            'metode_pembayaran' => [[
                'metode' => 'kpay',
                'nominal' => 50000,
                'nama_karyawan' => 'Budi',
                'identitas_karyawan' => '111',
            ]],
        ]);

        $r->assertOk();
        $entry = SetoranDetail::first();
        $this->assertSame('Budi', $entry->nama_karyawan);
        $this->assertSame('111', $entry->identitas_karyawan);
    }

    public function test_hapus_draft_di_riwayat_membersihkan_entries_dan_file(): void
    {
        Storage::fake('local');
        $user = $this->buatTeller();

        $harian = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $harian->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 5000,
            'foto_bukti_path' => '2026-08-22/dummy.jpg',
        ]);
        Storage::disk('local')->put('bukti_setoran/2026-08-22/dummy.jpg', 'isi file');

        $hapus = $this->actingAs($user)->deleteJson("/api/v1/teller/setoran/riwayat/{$harian->id}");
        $hapus->assertOk();

        $this->assertSame(0, SetoranDetail::count());
        Storage::disk('local')->assertMissing('bukti_setoran/2026-08-22/dummy.jpg');
    }

    public function test_hapus_submitted_dengan_entry_tetap_ditolak(): void
    {
        $user = $this->buatTeller();

        $harian = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
        ]);
        $harian->entries()->create(['metode_pembayaran' => 'kpay', 'nominal' => 5000]);

        $hapus = $this->actingAs($user)->deleteJson("/api/v1/teller/setoran/riwayat/{$harian->id}");

        $hapus->assertStatus(422);
        $this->assertSame(1, SetoranDetail::count());
    }

    public function test_submit_ditolak_snapshot_belum_dimuat_pakai_error_code_spesifik(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-18',
            'status' => 'submitted',
            'submitted_at' => now(),
            'snapshot_sopp' => null,
        ]);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 100000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'selisih_belum_dimuat');
        $this->assertSame(0, SetoranDetail::where('setoran_harian_id', $row->id)->count());
    }

    public function test_submit_total_melebihi_sisa_selisih_pakai_error_code_spesifik(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 999999, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'nominal_melebihi_selisih');
    }

    public function test_submit_sukses_response_berisi_total_setor_loket_dan_entries_breakdown(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        // total setor awal 300rb (dari helper), pendapatan sopp 500rb -> sisa selisih 200rb
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 150000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
                ['metode' => 'kpay', 'nominal' => 50000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '12345'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.selisih', 0)
            ->assertJsonPath('data.status_keuangan', 'lunas')
            // total_setor loket = 300rb (awal) + 150rb + 50rb (pelunasan baru)
            ->assertJsonPath('data.total_setor', 500000)
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.entries.0.metode_pembayaran', 'tunai')
            ->assertJsonPath('data.entries.0.nominal', 150000)
            ->assertJsonPath('data.entries.1.metode_pembayaran', 'kpay')
            ->assertJsonPath('data.entries.1.nama_karyawan', 'Budi');
    }

    public function test_submit_sukses_response_entries_menyertakan_foto_bukti_url(): void
    {
        // Regresi: entriesBaru() dulu dibangun manual di controller dan
        // kelupaan nyertain foto_bukti_url (foto tetap kesimpan ke disk,
        // cuma URL-nya gak dikirim balik ke FE). Sekarang harus lewat
        // SetoranDetail::toApiShape() -- satu sumber kebenaran yang sama
        // dipakai Detail Setoran, jadi field ini wajib selalu ada.
        Storage::fake('local');
        $teller = $this->buatTeller();
        $row = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-19', 500000, 300000, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-19',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 150000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
                ['metode' => 'kpay', 'nominal' => 50000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '12345'],
            ],
        ]);

        $response->assertOk();
        // Entry tunai punya bukti -> foto_bukti_url wajib terisi dan
        // mengarah ke route serve-foto yang benar (termasuk prefix v1).
        $urlTunai = $response->json('data.entries.0.foto_bukti_url');
        $this->assertNotNull($urlTunai);
        $this->assertStringContainsString('/api/v1/bukti-setoran/', $urlTunai);
        // Entry kpay memang gak wajib foto -> foto_bukti_url null, bukan error.
        $response->assertJsonPath('data.entries.1.foto_bukti_url', null);
    }

    public function test_submit_partial_response_total_setor_loket_gabungan_bukan_cuma_row_ini(): void
    {
        Storage::fake('local');
        $teller = $this->buatTeller();
        // 2 teller beda submit di loket+tanggal sama, total_setor harus GABUNGAN keduanya
        $tellerLain = $this->buatTeller();
        $rowTellerLain = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 100000, $tellerLain);
        $rowSaya = $this->buatRowSubmitted($this->loket->loket_id, '2026-08-18', 500000, 0, $teller);

        $response = $this->actingAs($teller)->postJson('/api/v1/teller/setoran/pelunasan', [
            'tanggal' => '2026-08-18',
            'metode_pembayaran' => [
                ['metode' => 'tunai', 'nominal' => 200000, 'bukti' => UploadedFile::fake()->image('a.jpg')],
            ],
        ]);

        // total_setor = 100rb (row teller lain) + 200rb (pelunasan saya) = 300rb, BUKAN 200rb doang
        $response->assertOk()->assertJsonPath('data.total_setor', 300000);
    }
    
}
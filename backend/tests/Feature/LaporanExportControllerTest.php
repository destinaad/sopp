<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * GET /api/v1/admin/laporan/export, GET /api/v1/admin/laporan/download.
 * Helper baca xlsx (bacaXlsx/cariBarisDiKolomA) SENGAJA ditiru persis
 * dari tests/Feature/RiwayatSetoranTest.php -- pola yang sama, bukan
 * reinvent.
 *
 * CATATAN: route '/admin/laporan/download' belum terdaftar di
 * routes/api.php per obrolan sebelumnya -- test download() di sini
 * bakal 404 sampai baris route-nya ditambah manual.
 */
class LaporanExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function buatAdminLaporan(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['nama' => 'Admin Laporan'], $overrides));
        $user->forceFill(['bisa_akses_laporan' => true])->save();

        return $user;
    }

    private function buatSetoranSubmitted(int $loketId, string $tanggal, array $metode): SetoranHarian
    {
        $user = User::factory()->create(['loket_id' => $loketId]);

        $setoran = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => $loketId,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'submitted_at' => $tanggal.' 12:00:00',
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

    private function mockBridgeKosong(): void
    {
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetch')->andReturn(['data' => [], 'grand_total' => 0]);
            $mock->shouldReceive('fetchDetailPerUser')->andReturn(['data' => [], 'grand_total' => 0]);
        });
    }

    /**
     * Bridge dengan 1 transaksi (loket 1) di tiap tanggal yang diberikan.
     * Bentuk B hanya menampilkan HARI AKTIF, jadi tes yang memeriksa baris
     * tanggal tertentu di file harus menyediakan transaksi di tanggal itu.
     *
     * @param  list<string>  $tanggalList
     */
    private function mockBridgeDenganTransaksi(array $tanggalList): void
    {
        $rows = array_map(fn (string $t) => [
            'tanggal' => $t, 'loket_id' => 1, 'vendor_id' => 1, 'vendor_name' => 'Finnet',
            'total' => 1000, 'user_id' => 'U001', 'user_name' => 'Budi',
        ], $tanggalList);

        $this->mock(BridgeService::class, function ($mock) use ($rows) {
            $mock->shouldReceive('fetch')->andReturn(['data' => $rows, 'grand_total' => 1000 * count($rows)]);
            $mock->shouldReceive('fetchDetailPerUser')->andReturn(['data' => $rows, 'grand_total' => 1000 * count($rows)]);
        });
    }

    private function bacaXlsx($response): Worksheet
    {
        return $this->bacaSpreadsheet($response)->getActiveSheet();
    }

    private function bacaSpreadsheet($response): Spreadsheet
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

        $path = tempnam(sys_get_temp_dir(), 'laporan_test_').'.xlsx';
        file_put_contents($path, $isi);

        $spreadsheet = IOFactory::load($path);
        unlink($path);

        return $spreadsheet;
    }

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
    // export() -- boleh loket_id kosong (Bentuk A, semua loket)
    // ===================================================================

    public function test_export_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/admin/laporan/export?mode=harian&tanggal=2026-09-01')
            ->assertStatus(401);
    }

    public function test_export_bukan_admin_laporan_ditolak_403(): void
    {
        $teller = User::factory()->create();
        $this->mockBridgeKosong();

        $this->actingAs($teller)
            ->get('/api/v1/admin/laporan/export?mode=harian&tanggal=2026-09-01')
            ->assertStatus(403);
    }

    public function test_export_mengembalikan_xlsx_dengan_content_type_benar(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=harian&tanggal=2026-09-01');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function test_export_mode_bulanan_berhasil(): void
    {
        // mode=bulanan (param `bulan=YYYY-MM`) belum PERNAH dites lewat
        // /export sama sekali sebelumnya -- cuma lewat index(). Rute
        // export/download pakai validasiFilterLaporan() yang SAMA, tapi
        // itu bukan alasan buat gak dites eksplisit di sini juga.
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=bulanan&bulan=2026-02');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function test_download_mode_bulanan_berhasil(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/download?mode=bulanan&bulan=2026-02&loket_id=1'
        );

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function test_export_bentuk_A_punya_kolom_loket_dan_baris_total_semua(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);

        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetch')->andReturn([
                'data' => [[
                    'tanggal' => '2026-09-01', 'loket_id' => 1, 'vendor_id' => 1,
                    'vendor_name' => 'Finnet', 'total' => 500000,
                ]],
                'grand_total' => 500000,
            ]);
        });

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02'
        );

        $sheet = $this->bacaXlsx($response);

        $barisHeader = $this->cariBarisDiKolomA($sheet, 'Loket');
        $this->assertNotNull($barisHeader, 'Header kolom Loket harus ada di export Bentuk A.');
        $this->assertSame('Loket Legi', $sheet->getCell([1, $barisHeader + 2])->getValue());

        $this->assertNotNull($this->cariBarisDiKolomA($sheet, 'TOTAL SEMUA'));
    }

    public function test_export_paginasi_false_semua_tanggal_ikut_bukan_cuma_8(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        // 10 hari aktif (hari tanpa aktivitas memang tidak jadi baris).
        $this->mockBridgeDenganTransaksi(array_map(fn (int $d) => sprintf('2026-08-%02d', $d), range(1, 10)));

        // 10 hari -- kalau paginasi ikut default 8/halaman, baris tanggal
        // ke-9/10 bakal ilang dari file.
        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&loket_id=1&tanggal_mulai=2026-08-01&tanggal_akhir=2026-08-10'
        );

        $sheet = $this->bacaXlsx($response);
        $this->assertNotNull($this->cariBarisDiKolomA($sheet, '10 Agt 2026'));
    }

    public function test_export_menulis_activity_log_laporan_diekspor(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=harian&tanggal=2026-09-01');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'jenis_aksi' => 'laporan_diekspor',
        ]);
    }

    // ===================================================================
    // download() -- WAJIB loket_id (kontrak: "untuk 1 loket spesifik")
    // ===================================================================

    public function test_download_tanpa_loket_id_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan/download?mode=harian&tanggal=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('loket_id');
    }

    public function test_download_dengan_loket_id_berhasil_dan_content_type_benar(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/download?mode=harian&loket_id=1&tanggal=2026-09-01'
        );

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function test_download_tidak_punya_kolom_loket_beda_dari_export(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        // download() -> loket_id WAJIB terisi -> hasilnya SELALU Bentuk
        // B/C (1 loket), yang dari awal memang gak pernah punya kolom
        // Loket per-baris (beda dari Bentuk A) -- jadi ini juga tidak
        // langsung membuktikan cabang sertakanKolomLoket=false di
        // tulisBentukA() (lihat catatan di respons: cabang itu jadi
        // TIDAK PERNAH tereksekusi lewat download(), karena Bentuk A
        // mustahil terjadi begitu loket_id diwajibkan).
        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/download?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02'
        );

        $sheet = $this->bacaXlsx($response);
        $this->assertNull($this->cariBarisDiKolomA($sheet, 'Loket'));
    }

    public function test_download_auto_carry_teller_id(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);

        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetchDetailPerUser')
                ->withArgs(fn ($mulai, $selesai, $loketId, $actor) => $loketId === 1)
                ->once()
                ->andReturn(['data' => [], 'grand_total' => 0]);
        });

        $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/download?mode=harian&loket_id=1&tanggal=2026-09-01&teller_id=U001'
        )->assertOk();

        // teller_id ada di FILTER header, bukan cuma dipakai di query --
        // buktiin end-to-end query-nya beneran jalan tanpa error, bukan
        // sekadar diterima validasi.
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'jenis_aksi' => 'laporan_diunduh',
        ]);
        $log = ActivityLog::where('jenis_aksi', 'laporan_diunduh')->first();
        $this->assertSame('U001', $log->detail_perubahan['teller_id']);
    }

    public function test_download_teller_id_tanpa_loket_id_tetap_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        // Guard umum (ValidatesFilterLaporan: teller_id prohibited tanpa
        // loket_id) HARUS tetap jalan duluan sebelum guard khusus
        // download (loket_id wajib) -- keduanya nolak 422, tapi pesan
        // errornya beda field.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan/download?mode=harian&tanggal=2026-09-01&teller_id=U001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('teller_id');
    }

    // ===================================================================
    // Split sheet per bulan
    // ===================================================================

    public function test_rentang_1_bulan_tetap_1_sheet_dinamai_bulan(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-15'
        );

        $spreadsheet = $this->bacaSpreadsheet($response);
        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame('September 2026', $spreadsheet->getSheetNames()[0]);
    }

    public function test_rentang_2_bulan_kepecah_jadi_2_sheet_terpisah_dinamai_bulan(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        // Hanya hari aktif yang jadi baris: sediakan transaksi di tanggal yang diperiksa.
        $this->mockBridgeDenganTransaksi(['2026-08-31', '2026-09-01']);

        // 15 Agustus - 10 September -> HARUS jadi 2 sheet: [15-31
        // Agustus] dan [1-10 September], BUKAN 1 sheet gepeng isi semua
        // 27 hari.
        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&loket_id=1&tanggal_mulai=2026-08-15&tanggal_akhir=2026-09-10'
        );

        $spreadsheet = $this->bacaSpreadsheet($response);
        $this->assertSame(2, $spreadsheet->getSheetCount());
        $this->assertSame(['Agustus 2026', 'September 2026'], $spreadsheet->getSheetNames());

        // Sheet Agustus cuma punya tanggal Agustus, sheet September cuma
        // punya tanggal September -- bukan tercampur.
        $sheetAgustus = $spreadsheet->getSheet(0);
        $this->assertNotNull($this->cariBarisDiKolomA($sheetAgustus, '31 Agt 2026'));
        $this->assertNull($this->cariBarisDiKolomA($sheetAgustus, '01 Sep 2026'));

        $sheetSeptember = $spreadsheet->getSheet(1);
        $this->assertNotNull($this->cariBarisDiKolomA($sheetSeptember, '01 Sep 2026'));
        $this->assertNull($this->cariBarisDiKolomA($sheetSeptember, '31 Agt 2026'));
    }

    public function test_bentukA_rentang_2_bulan_total_per_sheet_bukan_gabungan_2_bulan(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);

        // Bentuk A: loket_id KOSONG -- 1 baris/loket ter-AGGREGATE atas
        // periode yang diminta. Kalau split-sheet cuma motong array
        // (bukan re-generate per bulan), 2 sheet ini bakal punya angka
        // yang SAMA (keduanya nunjukin total gabungan 2 bulan) -- test
        // ini mastiin itu gak kejadian: tiap sheet cuma nunjukin
        // vendor_total BULAN ITU doang.
        $this->mock(BridgeService::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->withArgs(fn ($mulai, $selesai) => $mulai === '2026-08-01' && $selesai === '2026-08-31')
                ->once()
                ->andReturn(['data' => [[
                    'tanggal' => '2026-08-15', 'loket_id' => 1, 'vendor_id' => 1,
                    'vendor_name' => 'Finnet', 'total' => 100000,
                ]], 'grand_total' => 100000]);

            $mock->shouldReceive('fetch')
                ->withArgs(fn ($mulai, $selesai) => $mulai === '2026-09-01' && $selesai === '2026-09-05')
                ->once()
                ->andReturn(['data' => [[
                    'tanggal' => '2026-09-02', 'loket_id' => 1, 'vendor_id' => 1,
                    'vendor_name' => 'Finnet', 'total' => 250000,
                ]], 'grand_total' => 250000]);
        });

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-08-01&tanggal_akhir=2026-09-05'
        );

        $spreadsheet = $this->bacaSpreadsheet($response);
        $this->assertSame(2, $spreadsheet->getSheetCount());

        $sheetAgustus = $spreadsheet->getSheet(0);
        $barisLoket = $this->cariBarisDiKolomA($sheetAgustus, 'Loket A');
        $this->assertNotNull($barisLoket);
        // Kolom Finnet (kolom ke-2, setelah Loket) buat baris loket ini.
        $this->assertSame(100000, $sheetAgustus->getCell([2, $barisLoket])->getValue());

        $sheetSeptember = $spreadsheet->getSheet(1);
        $barisLoketSept = $this->cariBarisDiKolomA($sheetSeptember, 'Loket A');
        $this->assertSame(250000, $sheetSeptember->getCell([2, $barisLoketSept])->getValue());
    }

    public function test_mode_harian_split_bulan_tetap_1_sheet_1_baris(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=harian&loket_id=1&tanggal=2026-09-01'
        );

        $spreadsheet = $this->bacaSpreadsheet($response);
        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame('September 2026', $spreadsheet->getSheetNames()[0]);
    }

    /**
     * Regresi: chunk yang KEBETULAN cuma 1 hari (karena posisinya pas di
     * ujung bulan) TIDAK BOLEH ke-reklasifikasi jadi mode=harian kalau
     * request aslinya mode=rentang -- kalau itu kejadian, loket_id yang
     * terisi bakal salah route ke Bentuk C (bukan Bentuk B), bikin 1
     * sheet formatnya nyeleneh dibanding sheet lain di file yang sama.
     */
    public function test_rentang_dengan_chunk_1_hari_di_ujung_bulan_tetap_bentuk_B_bukan_C(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        $this->mockBridgeKosong();

        // WAJIB ada entry beneran di 31 Jan -- kalau mock-nya kosong
        // total, `rincian_transaksi` bakal kosong juga WALAUPUN salah
        // ke-route ke Bentuk C (karena section RINCIAN cuma ditulis
        // kalau entry-nya gak kosong), jadi assertion "gak ada RINCIAN"
        // gak akan beneran nangkep bug. Dengan entry ini, RINCIAN
        // Transfer PASTI muncul kalau (dan cuma kalau) salah route ke C.
        $this->buatSetoranSubmitted(1, '2026-01-31', ['transfer' => 100000]);

        // 31 Jan - 5 Mar -> chunk Januari cuma [31 Jan, 31 Jan], 1 hari.
        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&loket_id=1&tanggal_mulai=2026-01-31&tanggal_akhir=2026-03-05'
        );

        $spreadsheet = $this->bacaSpreadsheet($response);
        $this->assertSame(3, $spreadsheet->getSheetCount());
        $this->assertSame(['Januari 2026', 'Februari 2026', 'Maret 2026'], $spreadsheet->getSheetNames());

        // Sheet Januari (chunk 1-hari) HARUS tetap Bentuk B: baris
        // tanggal '31 Jan 2026' muncul di kolom A (bukti item
        // per-tanggal, ciri Bentuk B), DAN section 'RINCIAN Transfer'
        // (ciri Bentuk C) TIDAK ADA -- walau ada entry transfer beneran
        // yang PASTI bakal nongol di RINCIAN kalau salah route ke C.
        $sheetJanuari = $spreadsheet->getSheet(0);
        $this->assertNotNull($this->cariBarisDiKolomA($sheetJanuari, '31 Jan 2026'));
        $this->assertNull($this->cariBarisDiKolomA($sheetJanuari, 'RINCIAN Transfer'));
    }

    // ===================================================================
    // Patch: /export dan /download sekarang meneruskan ?refresh=true
    // (dipaketkan dengan throttle yang sama seperti index())
    // ===================================================================

    public function test_export_refresh_true_memaksa_bridge_dipanggil_ulang_bypass_cache(): void
    {
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);

        $mock = \Mockery::mock(BridgeService::class);
        // Warm-up (request pertama, tanpa refresh): bridge kepanggil 1x,
        // hasilnya 500000, ke-cache.
        $mock->shouldReceive('fetch')->once()->andReturn([
            'data' => [[
                'tanggal' => '2026-09-01', 'loket_id' => 1, 'vendor_id' => 1,
                'vendor_name' => 'Finnet', 'total' => 500000,
            ]],
            'grand_total' => 500000,
        ]);
        // Request kedua PAKAI refresh=true: HARUS tetap manggil bridge
        // (bukan cache-hit dari warm-up), walau TTL belum lewat sama
        // sekali -- hasil baru (999000) yang harus muncul di sheet.
        $mock->shouldReceive('fetch')->once()->andReturn([
            'data' => [[
                'tanggal' => '2026-09-01', 'loket_id' => 1, 'vendor_id' => 1,
                'vendor_name' => 'Finnet', 'total' => 999000,
            ]],
            'grand_total' => 999000,
        ]);
        $this->app->instance(BridgeService::class, $mock);

        $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02'
        );

        $response = $this->actingAs($admin)->get(
            '/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true'
        );

        $sheet = $this->bacaXlsx($response);
        $barisLoket = $this->cariBarisDiKolomA($sheet, 'Loket Legi');
        $this->assertNotNull($barisLoket);
        // Kolom 2 = kolom vendor pertama (Finnet), tepat setelah kolom Loket.
        $this->assertSame(999000, $sheet->getCell([2, $barisLoket])->getValue());
    }

    public function test_export_refresh_dua_kali_berturut_turut_kena_429(): void
    {
        // Guard: refresh export TETAP kena rate-limit yang sama seperti
        // index() -- bukan jalur bebas hammer bridge tanpa batas.
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertStatus(429);
    }

    public function test_export_tanpa_refresh_tidak_kena_rate_limit_sama_sekali(): void
    {
        // Guard sebaliknya: request BIASA (gak minta refresh) gak boleh
        // ikut kepotong throttle -- cuma aksi refresh eksplisit yang
        // dibatasi, bukan traffic normal ke /export.
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);
        $this->mockBridgeKosong();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)
                ->get('/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02')
                ->assertOk();
        }
    }

    // ===================================================================
    // Batas rentang KHUSUS export/download (MAKS_HARI_EXPORT_DOWNLOAD=90)
    // -- LEBIH KETAT dari MAKS_HARI_RENTANG_LAPORAN=366 yang dipakai
    // index(). Pasangan test sama persis polanya dengan
    // test_rentang_lebih_dari_366_hari_ditolak_422 /
    // test_rentang_persis_366_hari_diterima di LaporanControllerTest.
    // ===================================================================

    public function test_export_rentang_lebih_dari_90_hari_ditolak_422(): void
    {
        $admin = $this->buatAdminLaporan();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-01-01&tanggal_akhir=2026-04-02')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_akhir');
    }

    public function test_export_rentang_persis_90_hari_diterima(): void
    {
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=rentang&tanggal_mulai=2026-01-01&tanggal_akhir=2026-04-01')
            ->assertOk();
    }

    public function test_download_rentang_lebih_dari_90_hari_JUGA_ditolak_422(): void
    {
        // unduh() dipakai BERSAMA export()+download() -- pastikan batas
        // ini ke-cover di /download juga, bukan cuma /export (persis
        // alasan yang sama dengan test forceRefresh+throttle di atas).
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/download?mode=rentang&loket_id=1&tanggal_mulai=2026-01-01&tanggal_akhir=2026-04-02')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_akhir');
    }

    public function test_index_TIDAK_kena_batas_90_hari_cuma_export_download(): void
    {
        // MAKS_HARI_EXPORT_DOWNLOAD itu SENGAJA cuma di unduh() (export+
        // download), bukan di trait yang dipakai bareng index() juga --
        // index() tetap pakai batas 366 hari yang lebih longgar karena
        // paginated & gak sinkron kayak export/download.
        $admin = $this->buatAdminLaporan();
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&tanggal_mulai=2026-01-01&tanggal_akhir=2026-04-02')
            ->assertOk();
    }

    public function test_download_refresh_true_juga_dipaketkan_dengan_throttle_yang_sama(): void
    {
        // unduh() dipakai BERSAMA oleh export() dan download() -- pastikan
        // patch-nya ke-cover di /download juga, bukan cuma /export.
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/download?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/download?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertStatus(429);
    }

    public function test_refresh_export_dan_refresh_index_untuk_filter_sama_berbagi_1_budget(): void
    {
        // Dikonfirmasi sengaja (lihat komentar di kode): key rate-limit
        // export SAMA PERSIS formatnya dengan index(), termasuk mode --
        // jadi refresh dari index() lalu refresh dari export() untuk
        // KOMBINASI FILTER YANG SAMA memang harus berbagi budget (dua-duanya
        // memicu bridge refetch yang identik untuk cache-key yang identik).
        $admin = $this->buatAdminLaporan();
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Legi']);
        $this->mockBridgeKosong();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/laporan?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/api/v1/admin/laporan/export?mode=rentang&loket_id=1&tanggal_mulai=2026-09-01&tanggal_akhir=2026-09-02&refresh=true')
            ->assertStatus(429);
    }
}
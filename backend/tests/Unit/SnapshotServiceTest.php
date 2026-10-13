<?php

namespace Tests\Unit;

use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\BridgeService;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Carbon\Carbon;
use Tests\TestCase;

class SnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function buatLoket(int $id = 1): Loket
    {
        return Loket::create(['loket_id' => $id, 'nama_loket' => 'Loket ' . $id]);
    }

    private function bridgeMock(int $kaliDipanggil, array $return = ['data' => [], 'grand_total' => 100000]): BridgeService
    {
        $mock = Mockery::mock(BridgeService::class);
        $mock->shouldReceive('fetch')->times($kaliDipanggil)->andReturn($return);
        return $mock;
    }

    public function test_snapshot_masih_segar_tidak_manggil_bridge_lagi(): void
    {
        $this->buatLoket(1);
        $header = SetoranHarian::create([
            'user_id' => User::factory()->create(['loket_id' => 1])->id,
            'loket_id' => 1,
            'tanggal_transaksi' => '2026-08-14',
            'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 999999, 'fetched_at' => now()->subMinutes(5)->toDateTimeString()],
        ]);

        // TTL 10 menit, snapshot baru 5 menit -- MASIH SEGAR.
        // fetch() TIDAK BOLEH kepanggil sama sekali (times(0)).
        $service = new SnapshotService($this->bridgeMock(0));

        $hasil = $service->getForLoketTanggal(1, '2026-08-14');

        $this->assertSame(999999, $hasil['grand_total']);
    }

    public function test_snapshot_basi_manggil_bridge_lagi_dan_update_row(): void
    {
        $this->buatLoket(1);
        $header = SetoranHarian::create([
            'user_id' => User::factory()->create(['loket_id' => 1])->id,
            'loket_id' => 1,
            'tanggal_transaksi' => '2026-08-14',
            'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 111, 'fetched_at' => now()->subMinutes(15)->toDateTimeString()],
        ]);

        $service = new SnapshotService($this->bridgeMock(1, ['data' => [], 'grand_total' => 500000]));

        $hasil = $service->getForLoketTanggal(1, '2026-08-14');

        $this->assertSame(500000, $hasil['grand_total']);
        $this->assertSame(500000, $header->fresh()->snapshot_sopp['grand_total']);
    }

    public function test_belum_ada_row_sama_sekali_tetap_fetch_tapi_tidak_bikin_row_baru(): void
    {
        $this->buatLoket(1);
        // Sengaja TIDAK bikin row SetoranHarian apapun buat loket+tanggal ini.
        $service = new SnapshotService($this->bridgeMock(1, ['data' => [], 'grand_total' => 250000]));

        $hasil = $service->getForLoketTanggal(1, '2026-08-14');

        $this->assertSame(250000, $hasil['grand_total']); // hasil tetap dikembalikan
        $this->assertDatabaseCount('setoran_harian', 0); // tapi TIDAK bikin row baru
    }

    public function test_dua_row_loket_sama_dua_duanya_ikut_ke_update(): void
    {
        $this->buatLoket(1);
        $headerA = SetoranHarian::create([
            'user_id' => User::factory()->create(['loket_id' => 1])->id,
            'loket_id' => 1, 'tanggal_transaksi' => '2026-08-14', 'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 1, 'fetched_at' => now()->subMinutes(20)->toDateTimeString()],
        ]);
        $headerB = SetoranHarian::create([
            'user_id' => User::factory()->create(['loket_id' => 1])->id,
            'loket_id' => 1, 'tanggal_transaksi' => '2026-08-14', 'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 2, 'fetched_at' => now()->subMinutes(20)->toDateTimeString()],
        ]);

        $service = new SnapshotService($this->bridgeMock(1, ['data' => [], 'grand_total' => 777]));
        $service->getForLoketTanggal(1, '2026-08-14');

        $this->assertSame(777, $headerA->fresh()->snapshot_sopp['grand_total']);
        $this->assertSame(777, $headerB->fresh()->snapshot_sopp['grand_total']);
    }

    public function test_forceRefresh_true_tetap_manggil_bridge_walau_masih_segar(): void
    {
        $this->buatLoket(1);
        SetoranHarian::create([
            'user_id' => User::factory()->create(['loket_id' => 1])->id,
            'loket_id' => 1, 'tanggal_transaksi' => '2026-08-14', 'status' => 'draft',
            'snapshot_sopp' => ['data' => [], 'grand_total' => 1, 'fetched_at' => now()->subMinutes(1)->toDateTimeString()],
        ]);

        // Masih segar (1 menit), TAPI forceRefresh=true -> fetch() TETAP kepanggil.
        $service = new SnapshotService($this->bridgeMock(1, ['data' => [], 'grand_total' => 42]));

        $hasil = $service->getForLoketTanggal(1, '2026-08-14', forceRefresh: true);

        $this->assertSame(42, $hasil['grand_total']);
    }

    public function test_bridge_exception_tetap_menjalar_ke_pemanggil(): void
    {
        $this->buatLoket(1);
        $mock = Mockery::mock(BridgeService::class);
        $mock->shouldReceive('fetch')->once()->andThrow(new \App\Exceptions\BridgeException('gagal'));

        $service = new SnapshotService($mock);

        $this->expectException(\App\Exceptions\BridgeException::class);
        $service->getForLoketTanggal(1, '2026-08-14');
    }
    private function buatSubmitted(int $loketId, string $tanggal, string $namaTeller, int $pendapatanSopp, int $totalSetor): void
{
    $user = User::factory()->create(['loket_id' => $loketId, 'nama' => $namaTeller]);
    $row = SetoranHarian::create([
        'user_id' => $user->id,
        'loket_id' => $loketId,
        'tanggal_transaksi' => $tanggal,
        'status' => 'submitted',
        'submitted_at' => now(),
        'snapshot_sopp' => ['data' => [], 'grand_total' => $pendapatanSopp, 'fetched_at' => $tanggal . 'T10:00:00+07:00'],
    ]);
    if ($totalSetor > 0) {
        \App\Models\SetoranDetail::create([
            'setoran_harian_id' => $row->id,
            'metode_pembayaran' => 'tunai',
            'nominal' => $totalSetor,
            'foto_bukti_path' => 'bukti/x.jpg',
            'is_konfirmasi' => true,
        ]);
    }
}

private function bridgeMockDenganTanggal(array $tanggalList): BridgeService
{
    $mock = Mockery::mock(BridgeService::class);
    $mock->shouldReceive('fetch')->andReturn([
        'data' => array_map(fn ($t) => ['tanggal' => $t, 'vendor_id' => 1, 'total' => 1], $tanggalList),
        'grand_total' => count($tanggalList),
    ]);

    return $mock;
}

public function test_aktivitas_submitted_muncul_dengan_nama_teller_dan_status_submitted(): void
{
    $this->buatLoket(1);
    $this->buatSubmitted(1, '2026-08-06', 'Teller Satu', 400000, 400000);

    $service = new SnapshotService($this->bridgeMockDenganTanggal(['2026-08-06']));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(1, $hasil);
    $this->assertSame('2026-08-06', $hasil[0]['tanggal_transaksi']);
    $this->assertSame('Teller Satu', $hasil[0]['nama_teller']);
    $this->assertSame('submitted', $hasil[0]['status_workflow']);
    $this->assertSame('lunas', $hasil[0]['status_keuangan']);
}

public function test_draft_tidak_pernah_muncul_di_aktivitas_punya_sendiri_maupun_orang_lain(): void
{
    $this->buatLoket(1);
    $userDraft = User::factory()->create(['loket_id' => 1]);
    SetoranHarian::create([
        'user_id' => $userDraft->id, 'loket_id' => 1,
        'tanggal_transaksi' => '2026-08-06', 'status' => 'draft',
    ]);

    // Bridge gak punya data buat tanggal ini, jadi kalau draft
    // ke-exclude dengan benar, hasilnya harus kosong total.
    $service = new SnapshotService($this->bridgeMockDenganTanggal([]));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(0, $hasil);
}

public function test_belum_input_muncul_kalau_bridge_ada_data_tapi_nol_row_submitted_di_loket(): void
{
    $this->buatLoket(1);
    // Sengaja tidak ada row SetoranHarian apapun.
    $service = new SnapshotService($this->bridgeMockDenganTanggal(['2026-08-06']));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(1, $hasil);
    $this->assertSame('2026-08-06', $hasil[0]['tanggal_transaksi']);
    $this->assertSame('belum_input', $hasil[0]['status_workflow']);
    $this->assertNull($hasil[0]['nama_teller']);
    $this->assertNull($hasil[0]['total_pendapatan']);
}

public function test_tanggal_tanpa_bridge_dan_tanpa_row_tidak_muncul(): void
{
    $this->buatLoket(1);
    $this->buatSubmitted(1, '2026-08-06', 'Teller Satu', 400000, 400000);
    // Bridge cuma punya 08-06 -- tanggal lain memang tidak ada transaksi.
    $service = new SnapshotService($this->bridgeMockDenganTanggal(['2026-08-06']));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(1, $hasil);
}

public function test_dua_teller_submit_tanggal_sama_jadi_dua_baris_terpisah_bukan_digabung(): void
{
    $this->buatLoket(1);
    $this->buatSubmitted(1, '2026-08-06', 'Teller Satu', 400000, 250000);
    $this->buatSubmitted(1, '2026-08-06', 'Teller Dua', 400000, 150000);

    $service = new SnapshotService($this->bridgeMockDenganTanggal(['2026-08-06']));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(2, $hasil); // BUKAN 1 baris gabungan
    $namaTeller = collect($hasil)->pluck('nama_teller')->sort()->values()->all();
    $this->assertSame(['Teller Dua', 'Teller Satu'], $namaTeller);
    // total_setor tetap personal per baris, BUKAN digabung jadi 400000.
    $totalSetorList = collect($hasil)->pluck('total_setor')->sort()->values()->all();
    $this->assertSame([150000, 250000], $totalSetorList);
}

public function test_loket_lain_tidak_ikut_kehitung(): void
{
    $this->buatLoket(1);
    $this->buatLoket(2);
    $this->buatSubmitted(2, '2026-08-06', 'Teller Loket Lain', 400000, 400000);

    $service = new SnapshotService($this->bridgeMockDenganTanggal(['2026-08-06']));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07'); // loket 1, bukan 2

    // Bridge "ada data" tapi loket 1 nol row submitted -> belum_input,
    // bukan malah ke-isi data loket 2.
    $this->assertCount(1, $hasil);
    $this->assertSame('belum_input', $hasil[0]['status_workflow']);
}

public function test_dibatasi_8_baris_urut_tanggal_terbaru_dulu(): void
{
    $this->buatLoket(1);
    $tanggalList = [];
    for ($i = 1; $i <= 10; $i++) {
        $tanggal = Carbon::parse('2026-08-07')->subDays($i)->toDateString();
        $tanggalList[] = $tanggal;
        $this->buatSubmitted(1, $tanggal, "Teller {$i}", 100000, 100000);
    }

    $service = new SnapshotService($this->bridgeMockDenganTanggal($tanggalList));
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(8, $hasil); // dibatasi 8, walau ada 10
    $this->assertSame(Carbon::parse('2026-08-07')->subDays(1)->toDateString(), $hasil[0]['tanggal_transaksi']);
    $this->assertSame(Carbon::parse('2026-08-07')->subDays(8)->toDateString(), $hasil[7]['tanggal_transaksi']);
}

public function test_bridge_gagal_tetap_fallback_ke_aktivitas_submitted_yang_ada(): void
{
    $this->buatLoket(1);
    $this->buatSubmitted(1, '2026-08-06', 'Teller Satu', 400000, 400000);

    $mock = Mockery::mock(BridgeService::class);
    $mock->shouldReceive('fetch')->andThrow(new \App\Exceptions\BridgeException('gagal'));
    $service = new SnapshotService($mock);

    // Gak boleh melempar exception ke pemanggil -- beda dari
    // getForLoketTanggal() yang sengaja menjalarkan exception.
    $hasil = $service->aktivitasTerakhirUntukLoket(1, '2026-08-07');

    $this->assertCount(1, $hasil); // aktivitas yang sudah ada tetap tampil
    $this->assertSame('submitted', $hasil[0]['status_workflow']);
}
}
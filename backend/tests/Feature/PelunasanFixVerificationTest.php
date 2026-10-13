<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PelunasanController;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PelunasanFixVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function buatSnapshot(int $grandTotal): array
    {
        return [
            'data' => [],
            'grand_total' => $grandTotal,
            'fetched_at' => now()->toDateTimeString(),
        ];
    }

    // test_pelunasan_kedua_untuk_tanggal_sama_via_row_beda_sekarang_
    // ditolak DIHAPUS (28 Agustus 2026): premisnya ("kirim
    // setoran_harian_id row beda buat submit kedua") gak punya padanan
    // input lagi -- store() sekarang cuma nerima {tanggal,
    // metode_pembayaran}, gak ada ID row yang bisa diutak-atik dari FE
    // sama sekali. Skenario "submit dobel ke tanggal yang sama"-nya
    // sendiri masih dites, sekarang di
    // PelunasanDuplicateRowBugTest::test_user_ditolak_pelunasan_dua_kali_untuk_tanggal_sama_row_teller_lain_tetap_gak_kesentuh().

    /**
     * FIX #3: daftar tanggal tersedia gak boleh ada duplikat, walau
     * ada 2 row (2 teller) buat loket+tanggal yang sama.
     */
    public function test_daftar_tanggal_tersedia_tidak_duplikat(): void
    {
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Test']);
        $tellerA = User::factory()->create(['loket_id' => 1]);
        $tellerB = User::factory()->create(['loket_id' => 1]);
        $tanggal = '2026-08-01';
        $snapshot = $this->buatSnapshot(1_000_000); // grand_total > 0, belum ada setoran -> selisih > 0

        SetoranHarian::create([
            'user_id' => $tellerA->id, 'loket_id' => 1,
            'tanggal_transaksi' => $tanggal, 'status' => 'submitted',
            'snapshot_sopp' => $snapshot, 'submitted_at' => now(),
        ]);
        SetoranHarian::create([
            'user_id' => $tellerB->id, 'loket_id' => 1,
            'tanggal_transaksi' => $tanggal, 'status' => 'submitted',
            'snapshot_sopp' => $snapshot, 'submitted_at' => now(),
        ]);

        $this->actingAs($tellerA);
        $controller = app(PelunasanController::class);

        $req = Request::create('/x', 'GET');
        $req->setUserResolver(fn () => $tellerA);
        $res = $controller->index($req);
        $body = json_decode($res->getContent(), true);

        $daftarTanggal = $body['data']['tanggal_tersedia'];

        $this->assertCount(
            1,
            $daftarTanggal,
            'Tanggal yang sama dari 2 row beda harusnya cuma muncul 1x. Isi: ' . json_encode($daftarTanggal)
        );
    }

    /**
     * FIX #4: jumlah query ke DB pas ambil daftar tanggal tersedia
     * HARUS konstan, gak boleh naik seiring jumlah tanggal submitted.
     */
    public function test_daftar_tanggal_tersedia_tidak_n_plus_1(): void
    {
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Test']);
        $teller = User::factory()->create(['loket_id' => 1]);

        // Sengaja bikin BANYAK tanggal submitted -- 15 tanggal.
        foreach (range(1, 15) as $i) {
            SetoranHarian::create([
                'user_id' => $teller->id,
                'loket_id' => 1,
                'tanggal_transaksi' => now()->subDays($i)->toDateString(),
                'status' => 'submitted',
                'snapshot_sopp' => $this->buatSnapshot(1_000_000),
                'submitted_at' => now(),
            ]);
        }

        $this->actingAs($teller);
        $controller = app(PelunasanController::class);

        $req = Request::create('/x', 'GET');
        $req->setUserResolver(fn () => $teller);

        $jumlahQuery = 0;
        DB::listen(function () use (&$jumlahQuery) {
            $jumlahQuery++;
        });

        $controller->index($req);

        // Sebelum fix: ini akan mendekati 1 (get awal) + 15 (satu per
        // tanggal). Setelah fix: harus konstan kecil, gak ikut jumlah
        // tanggal. Threshold 5 dikasih longgar buat query lain2 yang
        // wajar (auth, dsb), yang penting BUKAN 16+.
        $this->assertLessThan(
            6,
            $jumlahQuery,
            "Jumlah query = {$jumlahQuery}, ini indikasi N+1 masih ada (harusnya konstan, gak naik seiring jumlah tanggal)."
        );
    }

    public function test_file_bukti_dihapus_kalau_pelunasan_ditolak_setelah_file_kesimpen(): void
    {
        Storage::fake('local');

        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket Test']);
        $teller = User::factory()->create(['loket_id' => 1]);
        $tanggal = '2026-08-01';

        $row = SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => 1,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'snapshot_sopp' => [
                'data' => [],
                'grand_total' => 50000, // sengaja kecil
                'fetched_at' => now()->toDateTimeString(),
            ],
            'submitted_at' => now(),
        ]);

        $this->actingAs($teller);
        $controller = app(PelunasanController::class);

        $fileBukti = UploadedFile::fake()->image('bukti.jpg');

        $request = Request::create('/x', 'POST', [
            'tanggal' => $tanggal,
            'metode_pembayaran' => [[
                'metode' => 'transfer',
                'nominal' => 100000, // SENGAJA > sisa_selisih (50000) -> harus ditolak
            ]],
        ], files: [
            'metode_pembayaran' => [['bukti' => $fileBukti]],
        ]);
        $request->setUserResolver(fn () => $teller);

        $response = $controller->store($request);

        // Harus ditolak karena nominal > sisa_selisih -- tapi ini
        // terjadi SETELAH file udah kesimpen (karena urutan barunya:
        // simpan file dulu, baru validasi bisnis di dalam transaction).
        $this->assertSame(422, $response->getStatusCode(), 'Body: ' . $response->getContent());

        // Ini yang mau dibuktikan: foldernya harus KOSONG, gak ada
        // file bukti yang nyangkut walau request-nya gagal.
        Storage::disk('local')->assertDirectoryEmpty('bukti_setoran/' . $tanggal);
    }

}
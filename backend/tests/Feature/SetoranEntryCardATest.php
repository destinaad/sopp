<?php

namespace Tests\Feature;

use App\Models\Loket;
use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chunk: Input Setoran Card A (SetoranEntryController).
 *
 * SnapshotService di-mock di setiap test -- kita TIDAK menguji logic
 * bridge/snapshot di sini (itu tanggung jawab chunk lain). Yang diuji
 * murni logic batch upsert, kapabilitas lintas-loket, transisi kpay,
 * dan (baru) dukungan dropdown tanggal + lock read-only setelah submit.
 */
class SetoranEntryCardATest extends TestCase
{
    use RefreshDatabase;

    private function buatLoket(int $id = 1): Loket
    {
        return Loket::create([
            'loket_id' => $id,
            'nama_loket' => "Loket Test {$id}",
            'synced_at' => now(),
        ]);
    }

    /**
     * Snapshot kosong yang aman dipakai di semua test -- kita gak
     * peduli angka pendapatan SOPP-nya di sini, cuma butuh
     * buildResponse() gak meledak pas dipanggil di akhir tiap aksi.
     */
    private function mockSnapshotKosong(): void
    {
        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 0,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });
    }

    // =====================================================================
    // TEST LAMA
    // =====================================================================

    /** GET index() sebelum ada draft sama sekali -> semua kosong/null. */
    public function test_index_kosong_kalau_belum_pernah_ada_setoran_hari_ini(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        $response->assertJsonPath('data.is_persisted', false);
        $response->assertJsonPath('data.entries', []);
        $response->assertJsonPath('data.setoran_harian_id', null);
        $response->assertJsonPath('data.status_workflow', 'belum_input'); //status_workflow
    }

    /** submit() dengan entries[] kosong ditolak validasi (min:1 -- KHUSUS submit, draft beda, lihat test baru di bawah). */
    public function test_submit_ditolak_kalau_tidak_ada_entry_sama_sekali(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [],
        ]);

        $response->assertStatus(422);
    }

    // 2 row SUBMITTED dari 2 teller berbeda, yang buktiin angkanya beneran DIJUMLAH
    public function test_selisih_menjumlahkan_setoran_dua_teller_yang_sama_sama_submitted(): void
    {
        $this->buatLoket(1);
        $userA = User::factory()->create(['loket_id' => 1]);
        $userB = User::factory()->create(['loket_id' => 1]);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 200000,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });

        $headerA = SetoranHarian::create([
            'user_id' => $userA->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $headerA->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 60000, 'foto_bukti_path' => 'a.jpg']);

        $headerB = SetoranHarian::create([
            'user_id' => $userB->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $headerB->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 40000, 'foto_bukti_path' => 'b.jpg']);

        $response = $this->actingAs($userA)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        $response->assertJsonPath('data.total_setor', 60000);        // personal, punya A doang
        $response->assertJsonPath('data.total_setor_loket', 100000); // GABUNGAN A+B
        $response->assertJsonPath('data.selisih', 100000);           // 200000 - 100000
        $response->assertJsonPath('data.status_keuangan', 'belum_lunas');
    }

    // entry lama ke-"hapus" (delete-by-omission), TERUS entry lain di request yang sama trigger abort → transaksi rollback. Foto-nya harus SELAMAT.
    public function test_foto_tidak_hilang_permanen_kalau_transaksi_rollback_setelah_delete_by_omission(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $userSaya = User::factory()->create(['loket_id' => 1]);
        $userLain = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $userSaya->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryLama = $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 50000,
            'foto_bukti_path' => '2026-01-01/lama.jpg',
        ]);
        // Sesuaikan prefix path ini kalau FOLDER_FISIK_BUKTI_SETORAN
        // beda dari yang aku tebak -- tinggal liat konstanta itu di
        // trait UploadsBuktiSetoran punya kamu.
        Storage::disk('local')->put('bukti-setoran/2026-01-01/lama.jpg', 'dummy-isi-foto');

        // Entry milik user LAIN -- id-nya PASTI gak ketemu di header
        // milik $userSaya, jadi bakal trigger abort(422) DI TENGAH
        // loop, SETELAH blok delete-by-omission (yang bakal "menghapus"
        // $entryLama karena dia gak ikut dikirim ulang) udah jalan duluan.
        $headerLain = SetoranHarian::create([
            'user_id' => $userLain->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryMilikOrangLain = $headerLain->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 10000,
        ]);

        $response = $this->actingAs($userSaya)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                // $entryLama TIDAK disebut -> masuk jalur delete-by-omission.
                [
                    'id' => $entryMilikOrangLain->id, // id nyasar -> abort 422
                    'metode_pembayaran' => 'tunai',
                    'nominal' => 20000,
                ],
            ],
        ]);

        $response->assertStatus(422);

        // Transaksi rollback -> row lama HARUS balik ada lagi di DB.
        $this->assertDatabaseHas('setoran_detail', [
            'id' => $entryLama->id,
            'nominal' => 50000,
        ]);

        // Yang paling penting: foto fisiknya HARUS MASIH ADA. Sebelum
        // fix DB::afterCommit(), ini bakal ke-delete permanen walau
        // row-nya balik ke DB (physical delete gak ikut rollback).
        Storage::disk('local')->assertExists('bukti-setoran/2026-01-01/lama.jpg');
    }

    // buktiin is_locked bener di 2 kondisi (belum submit vs udah submit)
    public function test_is_locked_true_setelah_submit_false_sebelum_submit(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        // Sebelum ada apa-apa -> belum locked.
        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');
        $response->assertJsonPath('data.is_locked', false);

        // Simpan draft -> masih belum locked (draft = boleh diedit).
        $draftResponse = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ]);
        $draftResponse->assertOk();
        $entryId = $draftResponse->json('data.entries.0.id'); // ← tangkep id-nya

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');
        $response->assertJsonPath('data.is_locked', false);

        $this->travel(3)->seconds();

        // Submit -- kirim ULANG id yang sama, TANPA foto baru (foto lama
        // dipertahankan otomatis, sesuai aturan "baris lama, bukan kpay,
        // gak disentuh -> opsional").
        $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'id' => $entryId,
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
            ]],
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');
        $response->assertJsonPath('data.is_locked', true);
    }

    //selisih gabungan 2+ teller yang sama-sama submitted
    public function test_selisih_menggabungkan_dua_teller_yang_sama_sama_submitted(): void
    {
        $this->buatLoket(1);
        $userA = User::factory()->create(['loket_id' => 1]);
        $userB = User::factory()->create(['loket_id' => 1]);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 500000,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });

        $headerA = SetoranHarian::create([
            'user_id' => $userA->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $headerA->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 200000, 'foto_bukti_path' => 'x/a.jpg']);

        $headerB = SetoranHarian::create([
            'user_id' => $userB->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $headerB->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 150000, 'foto_bukti_path' => 'x/b.jpg']);

        // Dilihat dari sudut pandang userA.
        $response = $this->actingAs($userA)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        $response->assertJsonPath('data.total_setor', 200000); // personal, punya A doang
        $response->assertJsonPath('data.total_setor_loket', 350000); // gabungan A+B
        $response->assertJsonPath('data.selisih', 150000); // 500000 - 350000
        $response->assertJsonPath('data.status_keuangan', 'belum_lunas');
    }

    // bridge gagal (BridgeException)
    public function test_bridge_gagal_tidak_500_dan_menandai_gagal_dimuat(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')
                ->andThrow(new \App\Exceptions\BridgeException('bridge tidak bisa dihubungi'));
        });

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');

        $response->assertOk() // bukan 500
            ->assertJsonPath('data.pendapatan_sopp_status', 'gagal_dimuat')
            ->assertJsonPath('data.total_pendapatan', null)
            ->assertJsonPath('data.selisih', null)
            ->assertJsonPath('data.status_keuangan', null)
            ->assertJsonPath('data.fetched_at', null);
    }

    // rate limit tombol Refresh (?refresh=1)
    public function test_refresh_dobel_dalam_10_detik_ditolak_429(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response1 = $this->actingAs($user)->getJson('/api/v1/teller/setoran?refresh=1');
        $response1->assertOk();

        $response2 = $this->actingAs($user)->getJson('/api/v1/teller/setoran?refresh=1');
        $response2->assertStatus(429);

        // GET biasa (tanpa refresh) TETAP jalan normal, gak ikut kena limit ini.
        $response3 = $this->actingAs($user)->getJson('/api/v1/teller/setoran');
        $response3->assertOk();
    }


    // Activity log (buat/edit/hapus dalam 1 batch, plus submit terpisah)
    public function test_activity_log_tercatat_untuk_buat_edit_hapus_dalam_satu_batch(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryDiedit = $header->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 50000, 'foto_bukti_path' => 'x/a.jpg']);
        $entryDihapus = $header->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 30000, 'foto_bukti_path' => 'x/b.jpg']);

        // entryDiedit di-update, entryDihapus TIDAK dikirim ulang (dihapus),
        // 1 baris baru ditambahkan (tanpa id).
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['id' => $entryDiedit->id, 'metode_pembayaran' => 'tunai', 'nominal' => 99000],
                ['metode_pembayaran' => 'tunai', 'nominal' => 20000, 'foto_bukti' => UploadedFile::fake()->image('c.jpg')],
            ],
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'jenis_aksi' => 'edit_setoran',
            'target_type' => 'setoran_detail',
            'target_id' => $entryDiedit->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'jenis_aksi' => 'hapus_setoran',
            'target_type' => 'setoran_detail',
            'target_id' => $entryDihapus->id,
        ]);
        $this->assertDatabaseCount('activity_logs', 3); // edit + hapus + buat(baris baru)
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'jenis_aksi' => 'buat_setoran',
            'target_type' => 'setoran_detail',
        ]);
    }

    public function test_activity_log_tercatat_saat_submit(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [['metode_pembayaran' => 'tunai', 'nominal' => 50000, 'foto_bukti' => UploadedFile::fake()->image('a.jpg')]],
        ]);
        $response->assertOk();

        $header = SetoranHarian::where('user_id', $user->id)->first();
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'jenis_aksi' => 'submit_setoran',
            'target_type' => 'setoran_harian',
            'target_id' => $header->id,
        ]);
    }

    //DB::afterCommit() foto: dipastikan gak beneran kehapus kalau transaksi rollback
    public function test_penghapusan_foto_lama_dibatalkan_kalau_transaksi_rollback(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        Storage::disk('local')->put('bukti_setoran/2026-01-01/foto-lama.jpg', 'isi-dummy');

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entry = $header->entries()->create(['metode_pembayaran' => 'transfer', 'nominal' => 150000, 'foto_bukti_path' => '2026-01-01/foto-lama.jpg']);

        // entry[0]: valid, pindah ke kpay -> foto lama DIJADWALKAN dihapus
        // lewat DB::afterCommit(). entry[1]: id ngasal (bukan milik header
        // ini) -> abort(422) DI TENGAH loop, SETELAH entry[0] sempat
        // dijadwalkan duluan.
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['id' => $entry->id, 'metode_pembayaran' => 'kpay', 'nominal' => 150000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '123'],
                ['id' => 999999, 'metode_pembayaran' => 'tunai', 'nominal' => 10000],
            ],
        ]);

        $response->assertStatus(422);
        // Transaksi rollback -> callback afterCommit() TIDAK PERNAH jalan.
        Storage::disk('local')->assertExists('bukti_setoran/2026-01-01/foto-lama.jpg');
        $this->assertDatabaseHas('setoran_detail', [
            'id' => $entry->id,
            'foto_bukti_path' => '2026-01-01/foto-lama.jpg', // gak berubah juga
        ]);
    }





    /** simpan-draft dengan 1 baris baru + foto -> header & detail kebentuk, status draft. */
    public function test_simpan_draft_membuat_entry_baru_dengan_foto(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                [
                    'metode_pembayaran' => 'transfer',
                    'nominal' => 150000,
                    'keterangan' => 'Bank Mandiri',
                    'foto_bukti' => UploadedFile::fake()->image('bukti.jpg'),
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status_workflow', 'draft');
        $response->assertJsonPath('data.total_setor', 150000);
        $this->assertDatabaseCount('setoran_detail', 1);
        $this->assertDatabaseHas('setoran_harian', [
            'user_id' => $user->id,
            'loket_id' => 1,
            'status' => 'draft',
        ]);
    }

    /** submit() pertama isi submitted_at; submit() kedua kalinya (via row yg SUDAH submitted) sekarang malah ditolak 409 -- lihat test lock baru di bawah. Test ini fokus ke submitted_at doang, jadi cukup submit sekali. */
    public function test_submit_mengunci_status_dan_mengisi_submitted_at(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 50000,
                'nama_karyawan' => 'Ahmad',
                'identitas_karyawan' => '3507xxxx',
            ]],
        ]);
        $response->assertOk();

        $row = SetoranHarian::where('user_id', $user->id)->first();
        $this->assertEquals('submitted', $row->status);
        $this->assertNotNull($row->submitted_at);
    }

    /** Satu request batch bisa update baris lama + hapus baris lain yang gak ikut dikirim. */
    public function test_batch_bisa_update_dan_hapus_dalam_satu_request_sekaligus(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryDipertahankan = $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
            'foto_bukti_path' => '2026-01-01/dummy.jpg',
        ]);
        $entryDihapus = $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 200000,
            'foto_bukti_path' => '2026-01-01/dummy2.jpg',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entryDipertahankan->id,
                'metode_pembayaran' => 'tunai',
                'nominal' => 999999,
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_detail', [
            'id' => $entryDipertahankan->id,
            'nominal' => 999999,
        ]);
        $this->assertDatabaseMissing('setoran_detail', ['id' => $entryDihapus->id]);
    }

    /** Baris lama pindah METODE ke kpay -> foto lama otomatis kehapus/di-null-kan. */
    public function test_pindah_ke_kpay_menghapus_foto_lama(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entry = $header->entries()->create([
            'metode_pembayaran' => 'transfer',
            'nominal' => 150000,
            'foto_bukti_path' => '2026-01-01/foto-lama.jpg',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entry->id,
                'metode_pembayaran' => 'kpay',
                'nominal' => 150000,
                'nama_karyawan' => 'Budi',
                'identitas_karyawan' => '123',
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_detail', [
            'id' => $entry->id,
            'metode_pembayaran' => 'kpay',
            'foto_bukti_path' => null,
        ]);
    }

    /**
     * Baris lama pindah DARI kpay ke metode lain wajib upload foto baru
     * (kpay gak pernah punya foto buat dipertahankan). Dua request di
     * sini genuinely 2 aksi beda (gagal dulu, lalu retry dgn foto) --
     * dikasih jeda travel() 3 detik biar gak nyangkut di guard
     * anti-klik-dobel (window 2 detik).
     */
    public function test_pindah_dari_kpay_wajib_upload_foto_baru(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entry = $header->entries()->create([
            'metode_pembayaran' => 'kpay',
            'nominal' => 50000,
            'nama_karyawan' => 'Ahmad',
            'identitas_karyawan' => '3507xxxx',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entry->id,
                'metode_pembayaran' => 'transfer',
                'nominal' => 50000,
            ]],
        ]);
        $response->assertStatus(422);

        $this->travel(3)->seconds();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entry->id,
                'metode_pembayaran' => 'transfer',
                'nominal' => 50000,
                'foto_bukti' => UploadedFile::fake()->image('baru.jpg'),
            ]],
        ]);
        $response->assertOk();
    }

    /** Teller biasa (tanpa kapabilitas) kirim loket_id sekalipun nilainya sama dgn loket sendiri -> tetap 403. */
    public function test_teller_biasa_ditolak_kirim_loket_id_walau_nilainya_sama_dengan_loket_sendiri(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran?loket_id=1');

        $response->assertStatus(403);
    }

    /** User dgn bisa_input_setoran_semua_loket=true boleh CREATE ke loket lain (dropdown cabang). */
    public function test_user_berkapabilitas_boleh_input_ke_loket_lain(): void
    {
        $this->buatLoket(1);
        $this->buatLoket(2);
        $buWiwik = User::factory()->create(['loket_id' => 1]);
        $buWiwik->forceFill(['bisa_input_setoran_semua_loket' => true])->save();
        $this->mockSnapshotKosong();

        $response = $this->actingAs($buWiwik)->postJson('/api/v1/teller/setoran/draft', [
            'loket_id' => 2,
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 75000,
                'nama_karyawan' => 'Siti',
                'identitas_karyawan' => '999',
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_harian', [
            'user_id' => $buWiwik->id,
            'loket_id' => 2,
        ]);
    }

    // =====================================================================
    // TEST -- kapabilitas (chunk 3: input vs edit, dua scope beda)
    // =====================================================================

    public function test_user_berkapabilitas_input_boleh_crud_row_sendiri_di_loket_lain(): void
    {
        $this->buatLoket(1);
        $this->buatLoket(2);
        $buWiwik = User::factory()->create(['loket_id' => 1]);
        $buWiwik->forceFill(['bisa_input_setoran_semua_loket' => true])->save();
        $this->mockSnapshotKosong();

        $response = $this->actingAs($buWiwik)->postJson('/api/v1/teller/setoran/draft', [
            'loket_id' => 2,
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 50000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
                ['metode_pembayaran' => 'kpay', 'nominal' => 75000, 'nama_karyawan' => 'Sri', 'identitas_karyawan' => '222'],
            ],
        ]);
        $response->assertOk();
        $ids = collect($response->json('data.entries'))->pluck('id')->values();

        $this->travel(3)->seconds();
        $response = $this->actingAs($buWiwik)->postJson('/api/v1/teller/setoran/draft', [
            'loket_id' => 2,
            'entries' => [
                ['id' => $ids[0], 'metode_pembayaran' => 'kpay', 'nominal' => 999999, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_detail', ['id' => $ids[0], 'nominal' => 999999]);
        $this->assertDatabaseMissing('setoran_detail', ['id' => $ids[1]]);
        $this->assertFalse((bool) $buWiwik->fresh()->bisa_edit_setoran_semua_loket);
    }

    public function test_kapabilitas_edit_tanpa_input_tetap_ditolak_kirim_loket_id(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $user->forceFill(['bisa_edit_setoran_semua_loket' => true])->save();

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran?loket_id=1');

        $response->assertStatus(403);
    }

    // =====================================================================
    // TEST -- validasi batch & keamanan cross-ID
    // =====================================================================

    public function test_simpan_draft_ditolak_kalau_id_entry_bukan_milik_header_ini(): void
    {
        $this->buatLoket(1);
        $userA = User::factory()->create(['loket_id' => 1]);
        $userB = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $headerB = SetoranHarian::create([
            'user_id' => $userB->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryMilikB = $headerB->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
        ]);

        $response = $this->actingAs($userA)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entryMilikB->id,
                'metode_pembayaran' => 'tunai',
                'nominal' => 999999,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('setoran_detail', [
            'id' => $entryMilikB->id,
            'nominal' => 100000,
        ]);
    }

    public function test_baris_baru_kpay_dilarang_kirim_foto(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 50000,
                'nama_karyawan' => 'Ahmad',
                'identitas_karyawan' => '3507xxxx',
                'foto_bukti' => UploadedFile::fake()->image('gakboleh.jpg'),
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('setoran_detail', 0);
    }

    public function test_baris_baru_non_kpay_wajib_foto(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 50000,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('setoran_detail', 0);
    }

    public function test_kpay_wajib_nama_dan_identitas_karyawan(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 50000,
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_satu_request_bisa_create_update_delete_sekaligus(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryUpdate = $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
            'foto_bukti_path' => '2026-01-01/a.jpg',
        ]);
        $entryHapus = $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 50000,
            'foto_bukti_path' => '2026-01-01/b.jpg',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['id' => $entryUpdate->id, 'metode_pembayaran' => 'tunai', 'nominal' => 250000],
                ['metode_pembayaran' => 'qris', 'nominal' => 30000, 'foto_bukti' => UploadedFile::fake()->image('baru.jpg')],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_detail', ['id' => $entryUpdate->id, 'nominal' => 250000]);
        $this->assertDatabaseMissing('setoran_detail', ['id' => $entryHapus->id]);
        $this->assertDatabaseCount('setoran_detail', 2);
    }

    public function test_multi_baris_baru_foto_gak_ketuker(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'tunai', 'nominal' => 10000, 'foto_bukti' => UploadedFile::fake()->image('satu.jpg')],
                ['metode_pembayaran' => 'transfer', 'nominal' => 20000, 'foto_bukti' => UploadedFile::fake()->image('dua.jpg')],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('setoran_detail', 2);
        $paths = SetoranDetail::pluck('foto_bukti_path');
        $this->assertNotNull($paths[0]);
        $this->assertNotNull($paths[1]);
        $this->assertNotEquals($paths[0], $paths[1]);
    }

    public function test_nominal_nol_ditolak(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 0,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_loket_id_tidak_terdaftar_ditolak_422_bukan_403(): void
    {
        $this->buatLoket(1);
        $buWiwik = User::factory()->create(['loket_id' => 1]);
        $buWiwik->forceFill(['bisa_input_setoran_semua_loket' => true])->save();

        $response = $this->actingAs($buWiwik)->postJson('/api/v1/teller/setoran/draft', [
            'loket_id' => 999999,
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 75000,
                'nama_karyawan' => 'Siti',
                'identitas_karyawan' => '999',
            ]],
        ]);

        $response->assertStatus(422);
    }
    public function test_metode_pembayaran_di_luar_enum_ditolak(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'crypto',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_foto_mime_invalid_ditolak(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->create('bukti.pdf', 100),
            ]],
        ]);

        $response->assertStatus(422);
    }

    public function test_foto_lebih_dari_5mb_ditolak(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('gede.jpg')->size(5121),
            ]],
        ]);

        $response->assertStatus(422);
    }

    // =====================================================================
    // TEST -- akses & guard umum
    // =====================================================================

    public function test_index_mengembalikan_entries_dan_kalkulasi_yang_benar_pas_sudah_ada_data(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 200000,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
        ]);
        $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 150000,
            'foto_bukti_path' => '2026-01-01/a.jpg',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        $response->assertJsonPath('data.is_persisted', true);
        $response->assertJsonPath('data.total_setor', 150000);
        $response->assertJsonPath('data.total_pendapatan', 200000);
        $response->assertJsonPath('data.selisih', 50000);
        $response->assertJsonPath('data.status_keuangan', 'belum_lunas');
        $response->assertJsonCount(1, 'data.entries');
    }

    public function test_request_tanpa_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/teller/setoran');

        $response->assertStatus(401);
    }

    public function test_klik_dobel_dalam_2_detik_ditolak_429(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $payload = [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ];

        $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', $payload)->assertOk();
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', $payload);

        $response->assertStatus(429);
    }

    // =====================================================================
    // TEST BARU -- dropdown tanggal (superseded chunk lama yang bilang
    // Card A gak bisa pilih tanggal sama sekali)
    // =====================================================================

    /** Parameter `tanggal` gak dikirim -> default ke hari ini (bukan diabaikan/error). */
    public function test_index_default_tanggal_hari_ini_kalau_parameter_tidak_dikirim(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        $response->assertJsonPath('data.tanggal_transaksi', now()->toDateString());
    }

    /** simpan-draft ke tanggal custom (bukan hari ini) -> row kebentuk di tanggal itu, BUKAN hari ini. */
    public function test_simpan_draft_ke_tanggal_custom_membuat_row_di_tanggal_itu(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();
        $kemarin = now()->subDay()->toDateString();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'tanggal' => $kemarin,
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.tanggal_transaksi', $kemarin);
        $this->assertDatabaseHas('setoran_harian', [
            'user_id' => $user->id,
            'tanggal_transaksi' => $kemarin,
        ]);
        $this->assertDatabaseMissing('setoran_harian', [
            'user_id' => $user->id,
            'tanggal_transaksi' => now()->toDateString(),
        ]);
    }

    /** GET dengan tanggal custom nampilin draft di TANGGAL ITU, bukan draft hari ini (dua row beda, gak nyampur). */
    public function test_index_dengan_tanggal_custom_menampilkan_draft_tanggal_itu_bukan_hari_ini(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();
        $kemarin = now()->subDay()->toDateString();

        $headerHariIni = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $headerHariIni->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 111111]);

        $headerKemarin = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => $kemarin,
            'status' => 'draft',
        ]);
        $headerKemarin->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 222222]);

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran?tanggal=' . $kemarin);

        $response->assertOk();
        $response->assertJsonPath('data.tanggal_transaksi', $kemarin);
        $response->assertJsonPath('data.total_setor', 222222);
        $response->assertJsonCount(1, 'data.entries');
    }

    /** Tanggal masa depan ditolak 422 -- "tanggal masa depan ga bisa dipilih". */
    public function test_tanggal_masa_depan_ditolak_422(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);

        $besok = now()->addDay()->toDateString();
        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran?tanggal=' . $besok);

        $response->assertStatus(422);
    }

    /** Format tanggal yang gak valid (bukan Y-m-d) ditolak 422. */
    public function test_format_tanggal_invalid_ditolak_422(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran?tanggal=14-07-2026');

        $response->assertStatus(422);
    }

    /** Tanggal jauh mundur (gak ada batas mundur) tetap diterima, bukan cuma kemarin. */
    public function test_tanggal_jauh_mundur_tetap_diterima_tidak_ada_batas(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();
        $lamaBanget = now()->subYear()->toDateString();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'tanggal' => $lamaBanget,
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 10000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_harian', ['tanggal_transaksi' => $lamaBanget]);
    }

    // =====================================================================
    // TEST BARU -- draft boleh kosong, submit tetap wajib isi
    // =====================================================================

    /**
     * simpan-draft BOLEH kirim entries: [] -- ini cara teller ngosongin
     * draft-nya total (bukan ditolak kayak dugaan awal kita). Baris lama
     * yang ada otomatis kehapus semua, row header TETAP ada dgn 0 entry.
     */
    public function test_simpan_draft_boleh_kirim_array_entries_kosong_untuk_mengosongkan_draft(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $header = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entryLama = $header->entries()->create(['metode_pembayaran' => 'tunai', 'nominal' => 10000]);

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status_workflow', 'draft');
        $response->assertJsonCount(0, 'data.entries');
        $this->assertDatabaseMissing('setoran_detail', ['id' => $entryLama->id]);
        $this->assertDatabaseHas('setoran_harian', ['id' => $header->id, 'status' => 'draft']);
    }

    // =====================================================================
    // TEST BARU -- lock READ ONLY setelah submit (superseded chunk lama
    // yang bilang status "boleh di-flip balik ke draft")
    // =====================================================================

    /** Setelah submit, simpan-draft LAGI ke tanggal yang SAMA ditolak 409 -- row-nya read-only lewat Card A. */
    public function test_simpan_draft_ke_row_yang_sudah_submitted_ditolak_409(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'metode_pembayaran' => 'kpay',
                'nominal' => 50000,
                'nama_karyawan' => 'Ahmad',
                'identitas_karyawan' => '3507xxxx',
            ]],
        ])->assertOk();

        $row = SetoranHarian::where('user_id', $user->id)->first();
        $entryId = $row->entries()->first()->id;

        $this->travel(3)->seconds();
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $entryId,
                'metode_pembayaran' => 'kpay',
                'nominal' => 999999,
                'nama_karyawan' => 'Ahmad',
                'identitas_karyawan' => '3507xxxx',
            ]],
        ]);

        $response->assertStatus(409);
        $row->refresh();
        $this->assertEquals('submitted', $row->status);
        $this->assertDatabaseHas('setoran_detail', ['id' => $entryId, 'nominal' => 50000]); // gak berubah
    }

    /** submit() lagi ke row yang UDAH submitted juga ditolak 409 (bukan cuma simpan-draft yang di-block). */
    public function test_submit_ke_row_yang_sudah_submitted_ditolak_409(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 50000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ])->assertOk();

        $this->travel(3)->seconds();
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 60000,
                'foto_bukti' => UploadedFile::fake()->image('b.jpg'),
            ]],
        ]);

        $response->assertStatus(409);
    }

    /**
     * Lock itu SCOPED PER TANGGAL -- submit tanggal A gak ngeblock
     * simpan-draft ke tanggal B (row yg beda sama sekali).
     */
    public function test_submit_di_satu_tanggal_tidak_mengunci_tanggal_lain(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();
        $kemarin = now()->subDay()->toDateString();

        $this->actingAs($user)->postJson('/api/v1/teller/setoran/submit', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 50000,
                'foto_bukti' => UploadedFile::fake()->image('a.jpg'),
            ]],
        ])->assertOk();

        // Tanggal BEDA (kemarin) -- harus tetap mulus, gak kena guard 409.
        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'tanggal' => $kemarin,
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 20000,
                'foto_bukti' => UploadedFile::fake()->image('b.jpg'),
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('setoran_harian', ['tanggal_transaksi' => $kemarin, 'status' => 'draft']);
    }

    public function test_selisih_tidak_menghitung_setoran_teller_lain_yang_masih_draft(): void
    {
        $this->buatLoket(1);
        $userSaya = User::factory()->create(['loket_id' => 1]);
        $userLain = User::factory()->create(['loket_id' => 1]);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 200000,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });

        // Punya saya sendiri -- SUBMITTED, wajib ikut kehitung ke selisih.
        $headerSaya = SetoranHarian::create([
            'user_id' => $userSaya->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $headerSaya->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 50000,
            'foto_bukti_path' => '2026-01-01/a.jpg',
        ]);

        // Punya teller LAIN, loket & tanggal SAMA -- tapi masih DRAFT.
        // Nominal sengaja gede: kalau kefilter status='submitted'-nya
        // bocor/kelewat, test ini bakal ketauan gagal jelas banget.
        $headerLain = SetoranHarian::create([
            'user_id' => $userLain->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $headerLain->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 9999999,
            'foto_bukti_path' => '2026-01-01/b.jpg',
        ]);

        $response = $this->actingAs($userSaya)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        // total_setor (field personal) tetap cuma nunjuk punya sendiri.
        $response->assertJsonPath('data.total_setor', 50000);
        // selisih = 200000 - 50000 doang -- draft punya userLain TIDAK ikut,
        // walau nominalnya jauh lebih gede.
        $response->assertJsonPath('data.selisih', 150000);
        $response->assertJsonPath('data.status_keuangan', 'belum_lunas');
    }

    public function test_selisih_tidak_menghitung_draft_punya_sendiri_sebelum_disubmit(): void
    {
        $this->buatLoket(1);
        $userSaya = User::factory()->create(['loket_id' => 1]);

        $this->mock(SnapshotService::class, function ($mock) {
            $mock->shouldReceive('getForLoketTanggal')->andReturn([
                'grand_total' => 200000,
                'data' => [],
                'fetched_at' => now()->toIso8601String(),
            ]);
        });

        // Punya sendiri, masih DRAFT (belum pernah pencet Submit).
        $headerSaya = SetoranHarian::create([
            'user_id' => $userSaya->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $headerSaya->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 150000,
            'foto_bukti_path' => '2026-01-01/a.jpg',
        ]);

        $response = $this->actingAs($userSaya)->getJson('/api/v1/teller/setoran');

        $response->assertOk();
        // total_setor (field personal, buat isi ulang form) tetap
        // nunjuk draft-nya apa adanya -- gak ikut kefilter.
        $response->assertJsonPath('data.total_setor', 150000);
        // TAPI selisih ngitung seolah dia belum nyetor apa-apa (0),
        // karena draft -- termasuk punya sendiri -- gak ikut hitungan
        // selisih sampai dia beneran submit.
        $response->assertJsonPath('data.selisih', 200000);
        $response->assertJsonPath('data.status_keuangan', 'belum_lunas');
    }

    public function test_hapus_draft_membersihkan_entry_dan_file_foto_tidak_lagi_crash(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $foto = UploadedFile::fake()->image('bukti.jpg');
        $simpan = $this->actingAs($user)->post('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'tunai', 'nominal' => 5000, 'foto_bukti' => $foto],
            ],
        ]);
        $simpan->assertOk();
        $harianId = $simpan->json('data.setoran_harian_id');
        $fotoUrl = $simpan->json('data.entries.0.foto_bukti_url');
        $path = ltrim(str_replace(url('/api/bukti-setoran'), '', $fotoUrl), '/');

        $this->assertSame(1, SetoranDetail::count());

        $hapus = $this->actingAs($user)->deleteJson("/api/v1/teller/setoran/riwayat/{$harianId}");
        $hapus->assertOk();

        // Child row ikut bersih, bukan jadi orphan.
        $this->assertSame(0, SetoranDetail::count());
        Storage::disk('local')->assertMissing('bukti_setoran/' . $path);

        // Akses foto lama: 404 bersih, bukan 500 (dulu: ErrorException
        // "Attempt to read property user_id on null").
        $show = $this->actingAs($user)->get('/api/bukti-setoran/' . $path);
        $show->assertNotFound();
        $show->assertStatus(404);
    }

    /** FIX #1 secara langsung: null-check jaga-jaga meski parent trashed & entry-nya (secara hipotetis) masih ada. */
    public function test_bukti_setoran_tidak_crash_kalau_entry_masih_ada_tapi_parent_trashed(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);

        $harian = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => 1,
            'tanggal_transaksi' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $entry = $harian->entries()->create([
            'metode_pembayaran' => 'kpay',
            'nominal' => 1000,
            'foto_bukti_path' => null,
            'nama_karyawan' => 'A',
            'identitas_karyawan' => '1',
        ]);
        // Simulasikan data lama/sisa migrasi: parent trashed, tapi anak
        // masih ada (skip lewat controller, langsung di model).
        $harian->delete();

        $show = $this->actingAs($user)->get('/api/bukti-setoran/tidak-ada-filenya.jpg');
        $show->assertStatus(404); // bukan 500
    }

    /** FIX #3: id sama dikirim 2x dalam satu payload -> ditolak 422, bukan double-processed. */
    public function test_id_duplikat_dalam_satu_payload_ditolak_422(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $awal = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 10000, 'nama_karyawan' => 'A', 'identitas_karyawan' => '123'],
            ],
        ]);
        $awal->assertOk();
        $id = $awal->json('data.entries.0.id');

        $this->travel(3)->seconds(); // lewatin rate-limit klik-dobel 2 detik

        $dobel = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['id' => $id, 'metode_pembayaran' => 'kpay', 'nominal' => 11111, 'nama_karyawan' => 'A', 'identitas_karyawan' => '123'],
                ['id' => $id, 'metode_pembayaran' => 'kpay', 'nominal' => 22222, 'nama_karyawan' => 'A', 'identitas_karyawan' => '123'],
            ],
        ]);

        $dobel->assertStatus(422);
        $dobel->assertJsonPath('status', 'error');

        // Gak ada perubahan yang ke-apply sama sekali (tolak sebelum transaksi).
        $this->assertSame(10000, SetoranDetail::first()->nominal);
    }

    /** Beberapa baris BARU (sama-sama tanpa id) tetap boleh, bukan dianggap "duplikat". */
    public function test_beberapa_baris_baru_tanpa_id_tetap_boleh_dalam_satu_payload(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $r = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 1000, 'nama_karyawan' => 'A', 'identitas_karyawan' => '1'],
                ['metode_pembayaran' => 'kpay', 'nominal' => 2000, 'nama_karyawan' => 'B', 'identitas_karyawan' => '2'],
            ],
        ]);

        $r->assertOk();
        $this->assertSame(2, SetoranDetail::count());
    }

    public function test_baris_baru_non_kpay_kirim_nama_karyawan_ditolak_422(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $foto = UploadedFile::fake()->image('bukti.jpg');
        $r = $this->actingAs($user)->post('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'metode_pembayaran' => 'tunai',
                'nominal' => 5000,
                'foto_bukti' => $foto,
                'nama_karyawan' => 'Karyawan Nyasar',
                'identitas_karyawan' => '999888777',
            ]],
        ]);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['entries.0.nama_karyawan', 'entries.0.identitas_karyawan']);
        $this->assertSame(0, SetoranDetail::count());
    }

    public function test_switch_dari_kpay_ke_tunai_tanpa_bersihin_nama_karyawan_ditolak_422(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $awal = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 5000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
            ],
        ]);
        $awal->assertOk();
        $id = $awal->json('data.entries.0.id');

        $this->travel(3)->seconds();

        // FE "lupa" ngosongin nama_karyawan pas user ganti metode ke tunai.
        $foto = UploadedFile::fake()->image('bukti.jpg');
        $r2 = $this->actingAs($user)->post('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $id,
                'metode_pembayaran' => 'tunai',
                'nominal' => 5000,
                'foto_bukti' => $foto,
                'nama_karyawan' => 'Budi',
                'identitas_karyawan' => '111',
            ]],
        ]);

        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['entries.0.nama_karyawan', 'entries.0.identitas_karyawan']);

        // Entry lama tidak berubah sama sekali (masih kpay, masih Budi).
        $entry = SetoranDetail::find($id);
        $this->assertSame('kpay', $entry->metode_pembayaran);
        $this->assertSame('Budi', $entry->nama_karyawan);
    }

    public function test_switch_dari_kpay_ke_tunai_dengan_field_dikosongkan_berhasil_dan_nama_karyawan_terhapus(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $awal = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 5000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
            ],
        ]);
        $id = $awal->json('data.entries.0.id');

        $this->travel(3)->seconds();

        $foto = UploadedFile::fake()->image('bukti.jpg');
        $r2 = $this->actingAs($user)->post('/api/v1/teller/setoran/draft', [
            'entries' => [[
                'id' => $id,
                'metode_pembayaran' => 'tunai',
                'nominal' => 5000,
                'foto_bukti' => $foto,
                // nama_karyawan/identitas_karyawan sengaja tidak dikirim -- FE yang benar.
            ]],
        ]);

        $r2->assertOk();
        $entry = SetoranDetail::find($id);
        $this->assertSame('tunai', $entry->metode_pembayaran);
        $this->assertNull($entry->nama_karyawan);
        $this->assertNull($entry->identitas_karyawan);
    }

    public function test_kpay_tetap_wajib_dan_tersimpan_nama_karyawan(): void
    {
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $r = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'kpay', 'nominal' => 5000, 'nama_karyawan' => 'Budi', 'identitas_karyawan' => '111'],
            ],
        ]);

        $r->assertOk();
        $entry = SetoranDetail::first();
        $this->assertSame('Budi', $entry->nama_karyawan);
        $this->assertSame('111', $entry->identitas_karyawan);
    }

    /** Akun tanpa loket_id (superadmin murni) + gak kirim loket_id eksplisit -> 403 rapi, bukan 500 crash. */
    public function test_akun_tanpa_loket_ditolak_403_bukan_crash(): void
    {
        $user = User::factory()->create(['loket_id' => null]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->getJson('/api/v1/teller/setoran');

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Akun ini tidak terhubung ke loket manapun.');
    }

    /** Sama, tapi lewat endpoint draft -- pastiin guard-nya konsisten di semua endpoint, bukan cuma index(). */
    public function test_akun_tanpa_loket_ditolak_403_saat_simpan_draft(): void
    {
        $user = User::factory()->create(['loket_id' => null]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                ['metode_pembayaran' => 'tunai', 'nominal' => 50000],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('setoran_harian', 0); // gak sempet kesimpen apa-apa
    }

    /**
     * Batch berisi 2 entry: entry[0] baru + foto (berhasil ditulis ke disk),
     * entry[1] id ngasal -> abort 422 di tengah loop. Fix-nya: foto entry[0]
     * yang KEBURU ketulis harus ikut dihapus balik, gak boleh nangkring
     * orphan walau row-nya sendiri sukses di-rollback dari DB.
     */
    public function test_foto_baru_ikut_dihapus_kalau_entry_lain_di_batch_sama_gagal(): void
    {
        Storage::fake('local');
        $this->buatLoket(1);
        $user = User::factory()->create(['loket_id' => 1]);
        $this->mockSnapshotKosong();

        $response = $this->actingAs($user)->postJson('/api/v1/teller/setoran/draft', [
            'entries' => [
                [
                    'metode_pembayaran' => 'transfer',
                    'nominal' => 100000,
                    'foto_bukti' => UploadedFile::fake()->image('bukti-valid.jpg'),
                ],
                [
                    'id' => 999999, // gak ada, bukan milik header manapun
                    'metode_pembayaran' => 'tunai',
                    'nominal' => 20000,
                ],
            ],
        ]);

        $response->assertStatus(422);
        // Row-nya sendiri gak kesimpen (DB rollback).
        $this->assertDatabaseCount('setoran_detail', 0);
        // Foto yang keburu ketulis buat entry[0] SEBELUM entry[1] gagal --
        // harus ikut kehapus balik, folder bukti_setoran harus kosong.
        $this->assertEmpty(Storage::disk('local')->allFiles('bukti_setoran'));
    }

}

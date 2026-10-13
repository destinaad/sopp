<?php

namespace Tests\Feature;

use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk: Card A -- endpoint kalender status per tanggal
 * (SetoranEntryController::kalenderSetoran()).
 *
 * Cek LOKAL doang, gak ada bridge/SnapshotService yang perlu di-mock
 * di sini -- itu beda dari test Card A yang lain. ada_selisih SENGAJA
 * gak dites ada di response (itu domain Pelunasan, sudah diputuskan
 * dikeluarkan dari endpoint ini).
 *
 * "Hari ini" dibekukan ke 2026-08-15 (pertengahan bulan) supaya test
 * gak flaky kalau kebetulan dijalankan di tanggal 1 atau akhir bulan.
 */
class KalenderSetoranTest extends TestCase
{
    use RefreshDatabase;

    private Loket $loket;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->loket = Loket::create([
            'loket_id' => 1,
            'nama_loket' => 'Loket Test 1',
            'synced_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buatTeller(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'loket_id' => $this->loket->loket_id,
        ], $overrides));
    }

    public function test_belum_login_ditolak_401(): void
    {
        $this->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08')->assertStatus(401);
    }

    public function test_parameter_bulan_wajib(): void
    {
        $teller = $this->buatTeller();
        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender')->assertStatus(422);
    }

    public function test_format_bulan_salah_ditolak(): void
    {
        $teller = $this->buatTeller();
        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-8')->assertStatus(422);
        $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=15-08-2026')->assertStatus(422);
    }

    public function test_tanggal_tanpa_row_status_belum_input(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $response->assertOk();
        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-10');
        $this->assertSame('belum_input', $item['status']);
    }

    public function test_jumlah_hari_sesuai_jumlah_hari_di_bulan(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $response->assertOk()->assertJsonCount(31, 'data.kalender'); // Agustus = 31 hari
    }

    public function test_bug_rollover_hari_ini_tanggal_31_tidak_menggeser_bulan_target(): void
    {
        // REGRESI: createFromFormat('Y-m', $bulan) TANPA paksa day=1 di
        // string-nya (misal startOfMonth() dipanggil SETELAH parse) itu
        // rollover bug -- hari-di-bulan ikut ke-fill dari HARI INI, dan
        // kalau hari ini tanggal 31 sementara bulan target cuma 28 hari,
        // hasilnya diam-diam geser ke BULAN LAIN (Februari 31 -> Maret 3).
        // Test standar file ini beku di tanggal 15 (aman) makanya gak
        // pernah nyentuh bug ini -- sengaja override ke tanggal 31 di sini.
        Carbon::setTestNow('2026-01-31');
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-02');

        $response->assertOk()->assertJsonCount(28, 'data.kalender'); // Februari 2026 = 28 hari
        // Kalau rollover kejadian, tanggal di kalender bakal Maret
        // (28-31), bukan Februari (01-28) -- assert eksplisit biar
        // ketauan kalau regresi lagi, bukan cuma ngandelin count.
        $this->assertSame('2026-02-01', $response->json('data.kalender.0.tanggal'));
        $this->assertSame('2026-02-28', $response->json('data.kalender.27.tanggal'));
    }

    public function test_tanggal_dengan_draft_status_draft_dan_bisa_diinput_true(): void
    {
        $teller = $this->buatTeller();
        SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-10', // masa lalu relatif ke 2026-08-15
            'status' => 'draft',
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-10');
        $this->assertSame('draft', $item['status']);
        $this->assertTrue($item['bisa_diinput']); // draft masih boleh dilanjut, bukan dikunci
    }

    public function test_tanggal_submitted_status_submitted_dan_bisa_diinput_false(): void
    {
        $teller = $this->buatTeller();
        SetoranHarian::create([
            'user_id' => $teller->id,
            'loket_id' => $this->loket->loket_id,
            'tanggal_transaksi' => '2026-08-10',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-10');
        $this->assertSame('submitted', $item['status']);
        $this->assertFalse($item['bisa_diinput']); // sudah dikunci
    }

    public function test_hari_ini_bisa_diinput_true(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-15');
        $this->assertSame('belum_input', $item['status']);
        $this->assertTrue($item['bisa_diinput']);
    }

    public function test_tanggal_masa_depan_bisa_diinput_false(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-20'); // masa depan
        $this->assertFalse($item['bisa_diinput']);
    }

    public function test_tanggal_lampau_belum_input_bisa_diinput_true(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-10');
        $this->assertTrue($item['bisa_diinput']);
    }

    public function test_ada_selisih_sengaja_tidak_ada_di_response(): void
    {
        $teller = $this->buatTeller();

        $response = $this->actingAs($teller)->getJson('/api/v1/teller/setoran/kalender?bulan=2026-08');

        $item = collect($response->json('data.kalender'))->first();
        $this->assertArrayNotHasKey('ada_selisih', $item);
    }

    /** Lihat loket lain butuh bisa_input_setoran_semua_loket, sama kayak pola Card A yang lain. */
    public function test_loket_id_lain_tanpa_kapabilitas_ditolak_403(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $teller = $this->buatTeller();

        $this->actingAs($teller)
            ->getJson("/api/v1/teller/setoran/kalender?bulan=2026-08&loket_id={$loketLain->loket_id}")
            ->assertStatus(403);
    }

    public function test_loket_id_lain_dengan_kapabilitas_boleh(): void
    {
        $loketLain = Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket Lain', 'synced_at' => now()]);
        $buWiwik = $this->buatTeller();
        $buWiwik->forceFill(['bisa_input_setoran_semua_loket' => true])->save();

        SetoranHarian::create([
            'user_id' => $buWiwik->id,
            'loket_id' => $loketLain->loket_id,
            'tanggal_transaksi' => '2026-08-10',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($buWiwik)
            ->getJson("/api/v1/teller/setoran/kalender?bulan=2026-08&loket_id={$loketLain->loket_id}");

        $response->assertOk();
        $item = collect($response->json('data.kalender'))->firstWhere('tanggal', '2026-08-10');
        $this->assertSame('submitted', $item['status']);
    }
}
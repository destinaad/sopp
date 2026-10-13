<?php

namespace Tests\Feature;

use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /api/v1/bukti-setoran/{path}
 *
 * TEMUAN AUDIT 20 Agustus 2026: guard akses ORIGINAL cuma cek
 * is_superadmin || bisa_akses_laporan || pemilik row -- TIDAK pernah
 * ngecek bisa_edit_setoran_semua_loket sama sekali. Akibatnya
 * verifikator yang punya kapabilitas edit lintas-loket (mis. Bu Wiwik)
 * gak bisa liat foto bukti punya teller lain walau dia justru yang
 * ditugasin buat verifikasi/edit entry itu -- kecuali dia KEBETULAN
 * juga punya bisa_akses_laporan (kapabilitas beda, buat Laporan).
 *
 * FIX: bisa_edit_setoran_semua_loket sekarang ikut dicek, TAPI
 * kondisional ke status SUBMITTED doang -- konsisten sama aturan
 * draft-only-owner yang berlaku di seluruh sistem (Card A, Riwayat,
 * guard delete Riwayat): kapabilitas edit gak pernah buka akses ke
 * draft ORANG LAIN, gak peduli kapabilitas apapun yang dipunya.
 */
class BuktiSetoranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Loket::create(['loket_id' => 1, 'nama_loket' => 'Loket A']);
        Loket::create(['loket_id' => 2, 'nama_loket' => 'Loket B']);
    }

    private function buatEntryDenganFoto(int $loketId, string $status, User $pemilik): \App\Models\SetoranDetail
    {
        $header = SetoranHarian::create([
            'user_id' => $pemilik->id,
            'loket_id' => $loketId,
            'tanggal_transaksi' => '2026-08-10',
            'status' => $status,
        ]);

        Storage::disk('local')->put("bukti_setoran/2026-08-10/foto-{$header->id}.jpg", 'isi-dummy');

        return $header->entries()->create([
            'metode_pembayaran' => 'tunai',
            'nominal' => 100000,
            'foto_bukti_path' => "2026-08-10/foto-{$header->id}.jpg",
        ]);
    }

    public function test_owner_selalu_boleh_lihat_foto_sendiri_apapun_statusnya(): void
    {
        $teller = User::factory()->create(['loket_id' => 1]);
        $entry = $this->buatEntryDenganFoto(1, 'draft', $teller);

        $this->actingAs($teller)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertOk();
    }

    public function test_bisa_akses_laporan_boleh_lihat_foto_siapapun(): void
    {
        $tellerLain = User::factory()->create(['loket_id' => 2]);
        $petugasLaporan = User::factory()->create([
            'loket_id' => 1,
            'bisa_akses_laporan' => true,
        ]);
        $entry = $this->buatEntryDenganFoto(2, 'submitted', $tellerLain);

        $this->actingAs($petugasLaporan)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertOk();
    }

    public function test_superadmin_boleh_lihat_foto_siapapun(): void
    {
        $tellerLain = User::factory()->create(['loket_id' => 2]);
        $superadmin = User::factory()->create(['loket_id' => null, 'is_superadmin' => true]);
        $entry = $this->buatEntryDenganFoto(2, 'submitted', $tellerLain);

        $this->actingAs($superadmin)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertOk();
    }

    public function test_bisa_edit_setoran_semua_loket_BOLEH_lihat_foto_row_SUBMITTED_orang_lain(): void
    {
        $tellerLain = User::factory()->create(['loket_id' => 2]);
        $verifikator = User::factory()->create([
            'loket_id' => 1,
            'bisa_edit_setoran_semua_loket' => true,
            'bisa_akses_laporan' => false,
        ]);
        $entry = $this->buatEntryDenganFoto(2, 'submitted', $tellerLain);

        $this->actingAs($verifikator)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertOk();
    }

    public function test_bisa_edit_setoran_semua_loket_TETAP_DITOLAK_lihat_foto_row_DRAFT_orang_lain(): void
    {
        $tellerLain = User::factory()->create(['loket_id' => 2]);
        $verifikator = User::factory()->create([
            'loket_id' => 1,
            'bisa_edit_setoran_semua_loket' => true,
            'bisa_akses_laporan' => false,
        ]);
        $entry = $this->buatEntryDenganFoto(2, 'draft', $tellerLain);

        $this->actingAs($verifikator)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertStatus(403);
    }

    public function test_teller_biasa_tanpa_kapabilitas_apapun_ditolak_lihat_foto_orang_lain(): void
    {
        $tellerLain = User::factory()->create(['loket_id' => 2]);
        $tellerBiasa = User::factory()->create(['loket_id' => 1]);
        $entry = $this->buatEntryDenganFoto(2, 'submitted', $tellerLain);

        $this->actingAs($tellerBiasa)
            ->get('/api/v1/bukti-setoran/' . $entry->foto_bukti_path)
            ->assertStatus(403);
    }
}
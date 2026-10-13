<?php

namespace Tests\Feature;

use App\Models\Loket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk: daftar referensi loket (LoketController).
 *
 * Endpoint ini SENGAJA tidak digerbang kapabilitas apapun -- nama
 * cabang bukan data sensitif, cukup login biasa. Dipakai bareng oleh
 * Card A (bisa_input_setoran_semua_loket) dan nanti Detail Setoran
 * (bisa_edit_setoran_semua_loket), jadi tidak diuji terikat ke salah
 * satu kapabilitas itu di sini.
 */
class LoketControllerTest extends TestCase
{
    use RefreshDatabase;

    private function buatLoket(int $id, string $nama): Loket
    {
        return Loket::create([
            'loket_id' => $id,
            'nama_loket' => $nama,
            'synced_at' => now(),
        ]);
    }

    public function test_user_biasa_tanpa_kapabilitas_apapun_bisa_akses(): void
    {
        $this->buatLoket(1, 'Loket A');
        $user = User::factory()->create(['loket_id' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/lokets');

        $response->assertOk();
    }

    public function test_daftar_terurut_berdasarkan_nama_bukan_id(): void
    {
        $this->buatLoket(2, 'Zebra');
        $this->buatLoket(1, 'Alpha');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/lokets');

        $response->assertOk()
            ->assertJsonPath('data.0.nama_loket', 'Alpha')
            ->assertJsonPath('data.1.nama_loket', 'Zebra');
    }

    public function test_response_cuma_berisi_loket_id_dan_nama_loket(): void
    {
        $this->buatLoket(1, 'Loket A');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/lokets');

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => [['loket_id', 'nama_loket']]]);
    }

    public function test_tidak_ada_loket_balikin_array_kosong_bukan_error(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/lokets');

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_belum_login_ditolak_401(): void
    {
        $response = $this->getJson('/api/v1/lokets');

        $response->assertStatus(401);
    }
}
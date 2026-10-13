<?php

namespace Tests\Feature;

use App\Models\Loket;
use App\Models\User;
use App\Services\SsoTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Chunk: SSO & Provisioning -- khusus menutup ensureLoketExists(),
 * bagian yang mastiin tabel `lokets` selalu punya baris buat setiap
 * loket_id yang pernah dipakai user manapun, walau belum pernah
 * disinkron dari bridge (sopp:sync-loket masih manual).
 *
 * SsoTokenService di-fake lewat container binding, bukan bikin token
 * AES+JWT asli -- controller ini cuma dites dari titik setelah token
 * berhasil di-decode (payload array), verifikasi encoding/signature
 * itu sendiri tanggung jawab SsoTokenServiceTest terpisah (di luar
 * scope file ini).
 */
class SsoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeToken(array $payload): void
    {
        $fake = Mockery::mock(SsoTokenService::class);
        $fake->shouldReceive('verifyAndDecode')
            ->once()
            ->andReturn($payload);

        $this->app->instance(SsoTokenService::class, $fake);
    }

    public function test_login_dengan_loket_id_belum_dikenal_bikin_baris_placeholder(): void
    {
        $this->assertDatabaseMissing('lokets', ['loket_id' => 99]);

        $this->fakeToken([
            'user_id' => 'U00001',
            'loket_id' => 99,
            'nama' => 'Teller Cilandak',
        ]);

        $response = $this->postJson('/api/v1/sso/verify', ['token' => 'apapun']);

        $response->assertOk();

        $this->assertDatabaseHas('lokets', [
            'loket_id' => 99,
            'nama_loket' => 'Loket #99 (belum disinkron)',
        ]);
        $this->assertDatabaseHas('users', [
            'user_id_sopp' => 'U00001',
            'loket_id' => 99,
        ]);
    }

    public function test_login_dengan_loket_id_yang_sudah_ada_gak_bikin_duplikat_atau_timpa_nama(): void
    {
        Loket::create([
            'loket_id' => 5,
            'nama_loket' => 'Loket Sudirman',
            'synced_at' => now(),
        ]);

        $this->fakeToken([
            'user_id' => 'U00002',
            'loket_id' => 5,
            'nama' => 'Teller Sudirman',
        ]);

        $response = $this->postJson('/api/v1/sso/verify', ['token' => 'apapun']);

        $response->assertOk();

        // Nama asli yang udah ke-sync TIDAK boleh ketiban placeholder --
        // firstOrCreate() cuma insert kalau row-nya belum ada sama
        // sekali, gak pernah nimpa row yang sudah ada.
        $this->assertDatabaseHas('lokets', [
            'loket_id' => 5,
            'nama_loket' => 'Loket Sudirman',
        ]);
        $this->assertDatabaseCount('lokets', 1);
    }

    public function test_response_bawa_nama_loket_placeholder_yang_baru_dibikin(): void
    {
        $this->fakeToken([
            'user_id' => 'U00003',
            'loket_id' => 77,
            'nama' => 'Teller Baru',
        ]);

        $response = $this->postJson('/api/v1/sso/verify', ['token' => 'apapun']);

        $response->assertOk()
            ->assertJsonPath('data.user.nama_loket', 'Loket #77 (belum disinkron)');
    }

    public function test_user_lama_pindah_ke_loket_yang_belum_dikenal_tetap_bikin_placeholder(): void
    {
        $user = User::factory()->create([
            'user_id_sopp' => 'U00004',
            'loket_id' => 1,
        ]);

        $this->fakeToken([
            'user_id' => 'U00004',
            'loket_id' => 123,
            'nama' => $user->nama,
        ]);

        $response = $this->postJson('/api/v1/sso/verify', ['token' => 'apapun']);

        $response->assertOk();

        $this->assertDatabaseHas('lokets', ['loket_id' => 123]);
        $this->assertDatabaseHas('users', [
            'user_id_sopp' => 'U00004',
            'loket_id' => 123,
        ]);
    }
}
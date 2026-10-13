<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Loket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test otomatis POST /api/v1/sso/verify memakai token AES+JWT ASLI
 * (bukan mock SsoTokenService seperti SsoControllerTest), jadi seluruh
 * jalur dari decrypt, signature, kadaluarsa, replay, sampai
 * provisioning dan log ikut teruji.
 *
 * Menggantikan kasus manual SSO-01, 02, 03, 05, 06, 07, 13, 14 di
 * dokumen test SSO-Core (sisi backend-nya saja; perilaku layar FE
 * seperti redirect dan "Verifikasi Gagal" tetap dites manual).
 *
 * BATAS: token dibuat oleh helper di bawah yang meniru format yang
 * DIBACA SsoTokenService. Interoperabilitas dengan encoder mcrypt di
 * sopp_rincian_setoran.php sisi lama tetap hanya terbukti lewat SH-01.
 */
class SsoVerifyTokenTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SSO = 'kunci-sso-untuk-test';
    private const KEY_AES = 'kunci-aes-untuk-test';

    protected function setUp(): void
    {
        parent::setUp();

        // Singleton SsoTokenService dibuat lazy saat controller
        // dipanggil, jadi config ini pasti terbaca sebelum itu.
        config([
            'services.sopp_sso.key'     => self::KEY_SSO,
            'services.sopp_sso.aes_key' => self::KEY_AES,
        ]);
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Bikin token seperti yang dibuat sistem lama: JWT HS256 lalu
     * dienkripsi AES-256-CBC dengan IV acak di 16 byte pertama.
     */
    private function buatToken(array $payloadOverride = [], ?string $aesKey = null, ?string $ssoKey = null): string
    {
        $now = time();
        $payload = array_merge([
            'user_id'  => 'U-TEST-01',
            'loket_id' => 1,
            'nama'     => 'Teller Uji',
            'tanggal'  => date('Y-m-d'),
            'iat'      => $now,
            'exp'      => $now + 300,
        ], $payloadOverride);

        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body   = $this->b64url(json_encode($payload));
        $sig    = $this->b64url(hash_hmac('sha256', $header . '.' . $body, $ssoKey ?? self::KEY_SSO, true));
        $jwt    = $header . '.' . $body . '.' . $sig;

        $iv     = random_bytes(16);
        $key    = substr(hash('sha256', $aesKey ?? self::KEY_AES, true), 0, 32);
        $cipher = openssl_encrypt($jwt, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $this->b64url($iv . $cipher);
    }

    private function verify(string $token)
    {
        return $this->postJson('/api/v1/sso/verify', ['token' => $token]);
    }

    private function buatLoket(int $id, string $nama): Loket
    {
        return Loket::create(['loket_id' => $id, 'nama_loket' => $nama, 'synced_at' => now()]);
    }

    private function jumlahLog(string $jenisAksi): int
    {
        return ActivityLog::where('jenis_aksi', $jenisAksi)->count();
    }

    // ---------------------------------------------------------------
    // SSO-01: identitas baru -> auto-provisioning
    // ---------------------------------------------------------------

    public function test_sso01_pegawai_baru_dibuatkan_akun_tanpa_kapabilitas_dan_tercatat_di_log(): void
    {
        $this->buatLoket(1, 'Loket Uji Satu');

        $response = $this->verify($this->buatToken());

        $response->assertOk()
            ->assertJsonPath('data.user.nama', 'Teller Uji')
            ->assertJsonPath('data.user.loket_id', 1)
            ->assertJsonPath('data.user.nama_loket', 'Loket Uji Satu')
            ->assertJsonPath('data.user.is_superadmin', false)
            ->assertJsonPath('data.user.bisa_akses_laporan', false)
            ->assertJsonPath('data.user.bisa_atur_hak_akses', false)
            ->assertJsonPath('data.user.bisa_lihat_log', false)
            ->assertJsonPath('data.user.bisa_input_setoran_semua_loket', false)
            ->assertJsonPath('data.user.bisa_edit_setoran_semua_loket', false);

        $this->assertDatabaseHas('users', ['user_id_sopp' => 'U-TEST-01', 'loket_id' => 1]);
        $this->assertSame(1, $this->jumlahLog('auto_provisioning'));
        $this->assertAuthenticated('web');
    }

    // ---------------------------------------------------------------
    // SSO-02: identitas lama, loket sama -> kapabilitas utuh, tanpa log
    // ---------------------------------------------------------------

    public function test_sso02_pegawai_lama_loket_sama_kapabilitas_utuh_dan_tanpa_log_baru(): void
    {
        $this->buatLoket(1, 'Loket Uji Satu');
        $user = User::factory()->create(['user_id_sopp' => 'U-TEST-01', 'loket_id' => 1, 'nama' => 'Teller Uji']);
        $user->forceFill(['bisa_akses_laporan' => true])->save();

        $this->verify($this->buatToken())
            ->assertOk()
            ->assertJsonPath('data.user.bisa_akses_laporan', true);

        $this->assertTrue((bool) $user->fresh()->bisa_akses_laporan);
        $this->assertSame(0, $this->jumlahLog('auto_provisioning'));
        $this->assertSame(0, $this->jumlahLog('mutasi_loket_terdeteksi'));
        $this->assertSame(1, User::where('user_id_sopp', 'U-TEST-01')->count());
    }

    // ---------------------------------------------------------------
    // SSO-03: identitas lama, loket beda -> mutasi tercatat
    // ---------------------------------------------------------------

    public function test_sso03_pegawai_pindah_loket_tercatat_mutasi_dan_kapabilitas_tetap_utuh(): void
    {
        $this->buatLoket(1, 'Loket Uji Satu');
        $this->buatLoket(2, 'Loket Uji Dua');
        $user = User::factory()->create(['user_id_sopp' => 'U-TEST-01', 'loket_id' => 1, 'nama' => 'Teller Uji']);
        $user->forceFill(['bisa_edit_setoran_semua_loket' => true])->save();

        $this->verify($this->buatToken(['loket_id' => 2]))
            ->assertOk()
            ->assertJsonPath('data.user.loket_id', 2)
            ->assertJsonPath('data.user.nama_loket', 'Loket Uji Dua')
            ->assertJsonPath('data.user.bisa_edit_setoran_semua_loket', true);

        $this->assertSame(2, $user->fresh()->loket_id);
        $this->assertTrue((bool) $user->fresh()->bisa_edit_setoran_semua_loket);
        $this->assertSame(0, $this->jumlahLog('auto_provisioning'));
        $this->assertSame(1, $this->jumlahLog('mutasi_loket_terdeteksi'));

        $log = ActivityLog::where('jenis_aksi', 'mutasi_loket_terdeteksi')->firstOrFail();
        $this->assertEquals(1, $log->detail_perubahan['loket_lama']);
        $this->assertEquals(2, $log->detail_perubahan['loket_baru']);
    }

    // ---------------------------------------------------------------
    // SSO-05: token dipakai ulang ditolak
    // ---------------------------------------------------------------

    public function test_sso05_token_yang_sudah_dipakai_ditolak_pada_percobaan_kedua(): void
    {
        $this->buatLoket(1, 'Loket Uji Satu');
        $token = $this->buatToken();

        $this->verify($token)->assertOk();

        $this->verify($token)
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Token sudah pernah digunakan.');

        $this->assertSame(1, User::where('user_id_sopp', 'U-TEST-01')->count());
        $this->assertSame(1, $this->jumlahLog('auto_provisioning'));
    }

    // ---------------------------------------------------------------
    // SSO-06: token kadaluarsa ditolak
    // ---------------------------------------------------------------

    public function test_sso06_token_kadaluarsa_ditolak_dan_akun_tidak_terbentuk(): void
    {
        $now = time();

        $this->verify($this->buatToken(['iat' => $now - 400, 'exp' => $now - 10]))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token sudah kadaluarsa, silakan ulangi dari SOPP.');

        $this->assertDatabaseMissing('users', ['user_id_sopp' => 'U-TEST-01']);
        $this->assertSame(0, ActivityLog::count());
        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------
    // SSO-07: token rusak / terpotong / kunci salah ditolak
    // ---------------------------------------------------------------

    public function test_sso07a_token_yang_karakter_tengahnya_diubah_ditolak(): void
    {
        $token  = $this->buatToken();
        $tengah = intdiv(strlen($token), 2);

        $rusak = $token;
        for ($i = 0; $i < 5; $i++) {
            $rusak[$tengah + $i] = $token[$tengah + $i] === 'A' ? 'B' : 'A';
        }

        $this->verify($rusak)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token tidak valid.');

        $this->assertDatabaseMissing('users', ['user_id_sopp' => 'U-TEST-01']);
        $this->assertGuest('web');
    }

    public function test_sso07b_token_yang_terpotong_separuh_ditolak(): void
    {
        $token = $this->buatToken();

        $this->verify(substr($token, 0, intdiv(strlen($token), 2)))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token tidak valid.');

        $this->assertDatabaseMissing('users', ['user_id_sopp' => 'U-TEST-01']);
        $this->assertGuest('web');
    }

    public function test_sso07c_token_dienkripsi_dengan_kunci_aes_yang_salah_ditolak(): void
    {
        $this->verify($this->buatToken([], 'kunci-aes-salah'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token tidak valid.');

        $this->assertDatabaseMissing('users', ['user_id_sopp' => 'U-TEST-01']);
    }

    public function test_sso07d_token_ditandatangani_dengan_kunci_sso_yang_salah_ditolak(): void
    {
        $this->verify($this->buatToken([], null, 'kunci-sso-salah'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token tidak valid.');

        $this->assertDatabaseMissing('users', ['user_id_sopp' => 'U-TEST-01']);
    }

    // ---------------------------------------------------------------
    // Padanan backend SSO-09: tanpa token
    // ---------------------------------------------------------------

    public function test_sso09_tanpa_token_ditolak_validasi(): void
    {
        $this->postJson('/api/v1/sso/verify', [])->assertStatus(422);
        $this->postJson('/api/v1/sso/verify', ['token' => ''])->assertStatus(422);

        $this->assertSame(0, User::count());
    }

    // ---------------------------------------------------------------
    // SSO-13: nama di token menimpa nama lokal
    // ---------------------------------------------------------------

    public function test_sso13_nama_baru_di_token_memperbarui_nama_akun_tanpa_log_dan_tanpa_ubah_kapabilitas(): void
    {
        $this->buatLoket(1, 'Loket Uji Satu');
        $user = User::factory()->create(['user_id_sopp' => 'U-TEST-01', 'loket_id' => 1, 'nama' => 'Nama Lama']);
        $user->forceFill(['bisa_akses_laporan' => true])->save();

        $this->verify($this->buatToken(['nama' => 'Nama Baru']))
            ->assertOk()
            ->assertJsonPath('data.user.nama', 'Nama Baru')
            ->assertJsonPath('data.user.bisa_akses_laporan', true);

        $this->assertSame('Nama Baru', $user->fresh()->nama);
        $this->assertSame(0, ActivityLog::count());
    }

    // ---------------------------------------------------------------
    // SSO-14: akun SSO tidak bisa login manual
    // ---------------------------------------------------------------

    public function test_sso14_akun_sso_tanpa_password_tidak_bisa_login_manual(): void
    {
        $user = User::factory()->create(['user_id_sopp' => 'U-TEST-01', 'loket_id' => 1]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'U-TEST-01',
            'password'   => 'AsalAsalan1',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Username atau password salah.');

        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)->where('jenis_aksi', 'login_gagal')->exists()
        );
        $this->assertGuest('web');
    }
}
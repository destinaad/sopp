<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function buatSuperadminUntukTest(string $username = 'testadmin', string $password = 'rahasia123'): User
    {
        $user = User::factory()
            ->denganPassword($password)
            ->create(['user_id_sopp' => null]);

        // username BUKAN $fillable -- wajib forceFill, bukan create()
        $user->forceFill(['username' => $username])->save();

        return $user->fresh();
    }

    public function test_sesi_stateful_jalan_lewat_csrf_cookie_handshake(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $user = $this->buatSuperadminUntukTest();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['data' => ['user' => [
                'nama',
                'loket_id',
                'is_superadmin',
                'bisa_akses_laporan',
                'bisa_atur_hak_akses',
                'bisa_lihat_log',
                'bisa_input_setoran_semua_loket',
                'bisa_edit_setoran_semua_loket',
            ]]]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.nama', $user->nama);
    }

    public function test_login_gagal_identifier_tidak_ketemu(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'gak-ada-usernya',
            'password'   => 'apapun',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Username atau password salah.');
    }

    public function test_login_gagal_password_salah_dicatat_ke_log(): void
    {
        $user = $this->buatSuperadminUntukTest(password: 'bener');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'salah',
        ])->assertStatus(401);

        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)
                ->where('jenis_aksi', 'login_gagal')->exists()
        );
    }

    public function test_login_gagal_user_belum_pernah_set_password(): void
    {
        $user = User::factory()->create(['user_id_sopp' => null]); // password tetap null (default factory)
        $user->forceFill(['username' => 'testadmin'])->save();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'apapun',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Username atau password salah.');

        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)
                ->where('jenis_aksi', 'login_gagal')->exists()
        );
    }

    public function test_login_sukses_bentuk_response_identik_me(): void
    {
        $user = $this->buatSuperadminUntukTest();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'rahasia123',
        ])->assertOk()->assertJsonStructure(['data' => ['user' => [
            'nama',
            'loket_id',
            'is_superadmin',
            'bisa_akses_laporan',
            'bisa_atur_hak_akses',
            'bisa_lihat_log',
            'bisa_input_setoran_semua_loket',
            'bisa_edit_setoran_semua_loket',
        ]]]);

        $meResponse = $this->getJson('/api/v1/me')->assertOk();

        $this->assertSame(
            $loginResponse->json('data.user'),
            $meResponse->json('data.user')
        );

        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)
                ->where('jenis_aksi', 'login_manual')->exists()
        );
    }

    public function test_validasi_gagal_identifier_password_kosong(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier', 'password']);
    }

    public function test_rate_limit_percobaan_ke6_kena_429(): void
    {
        $this->buatSuperadminUntukTest(password: 'bener');

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'testadmin',
                'password'   => 'salah',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'salah',
        ])->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak percobaan. Coba lagi sebentar lagi.');
    }

    public function test_logout_sukses_dicatat_dan_sesi_invalid(): void
    {
        $user = $this->buatSuperadminUntukTest();
        $this->actingAs($user);

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)
                ->where('jenis_aksi', 'logout_manual')->exists()
        );

        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_logout_tanpa_sesi_valid(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_me_401_kalau_belum_login(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
    public function test_logout_dua_kali_berturut_turut(): void
    {
        $user = $this->buatSuperadminUntukTest();
        $this->actingAs($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    /**
     * CATATAN: invariant "user_id_sopp dan username tidak akan pernah
     * tabrakan" itu dijaga secara disiplin provisioning, BUKAN
     * constraint di level database. Kalau suatu saat dilanggar, lookup
     * orWhere() akan selalu ambil baris pertama di tabel apa pun
     * password yang dikirim -- silent lockout buat user kedua (bukan
     * celah keamanan, tapi bug UX). Dicatat sebagai follow-up terpisah.
     */
    public function test_login_lintas_kolom_user_id_sopp_dan_username_tidak_tertukar(): void
    {
        $userSso = User::factory()
            ->denganPassword('passSso')
            ->create(['user_id_sopp' => 'SOPP-01']);

        $userManual = User::factory()
            ->denganPassword('passManual')
            ->create(['user_id_sopp' => null]);
        $userManual->forceFill(['username' => 'budi'])->save();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'SOPP-01',
            'password'   => 'passSso',
        ])->assertOk()->assertJsonPath('data.user.nama', $userSso->nama);

        $this->postJson('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'budi',
            'password'   => 'passManual',
        ])->assertOk()->assertJsonPath('data.user.nama', $userManual->nama);
    }

    public function test_response_kapabilitas_mencerminkan_field_yang_di_grant(): void
    {
        $user = $this->buatSuperadminUntukTest();
        $user->forceFill([
            'bisa_akses_laporan' => true,
            'bisa_lihat_log' => true,
            // sisanya sengaja dibiarkan default false, buat mastiin
            // yang gak di-grant beneran ikut false, bukan ke-set true semua.
        ])->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'rahasia123',
        ])->assertOk();

        $response->assertJsonPath('data.user.is_superadmin', false)
            ->assertJsonPath('data.user.bisa_akses_laporan', true)
            ->assertJsonPath('data.user.bisa_atur_hak_akses', false)
            ->assertJsonPath('data.user.bisa_lihat_log', true)
            ->assertJsonPath('data.user.bisa_input_setoran_semua_loket', false)
            ->assertJsonPath('data.user.bisa_edit_setoran_semua_loket', false);

        // /me harus persis sama, bukan cuma /login.
        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.user.bisa_akses_laporan', true)
            ->assertJsonPath('data.user.bisa_lihat_log', true);
    }

    /**
     * Invarian keamanan penting: superadmin OTOMATIS true buat
     * bisa_akses_laporan/bisa_atur_hak_akses/bisa_lihat_log, TAPI TIDAK
     * OTOMATIS buat bisa_input_setoran_semua_loket/bisa_edit_setoran_semua_loket
     * -- itu emang harus di-grant eksplisit lewat Hak Akses, sesuai
     * keputusan "superadmin gak ada urusannya sama hal keuangan secara
     * default". Kalau suatu saat ada yang "nyamain" 2 field terakhir ini
     * biar ikut auto-true (kelihatan lebih konsisten padahal ngerusak
     * batasan keamanan), test ini yang bakal nangkep.
     */
    public function test_superadmin_auto_true_laporan_hak_akses_log_tapi_tidak_input_edit_setoran(): void
    {
        $user = $this->buatSuperadminUntukTest();
        $user->forceFill(['is_superadmin' => true])->save();
        // bisa_input_setoran_semua_loket & bisa_edit_setoran_semua_loket
        // SENGAJA dibiarkan false (default) -- gak di-grant sama sekali.

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'rahasia123',
        ])->assertOk();

        $response->assertJsonPath('data.user.is_superadmin', true)
            ->assertJsonPath('data.user.bisa_akses_laporan', true)      // auto-true
            ->assertJsonPath('data.user.bisa_atur_hak_akses', true)     // auto-true
            ->assertJsonPath('data.user.bisa_lihat_log', true)          // auto-true
            ->assertJsonPath('data.user.bisa_input_setoran_semua_loket', false) // TIDAK auto-true
            ->assertJsonPath('data.user.bisa_edit_setoran_semua_loket', false); // TIDAK auto-true
    }

    public function test_teller_biasa_semua_kapabilitas_false(): void
    {
        $user = $this->buatSuperadminUntukTest(); // nama helper misleading, tapi is_superadmin default false
        // Gak ada forceFill kapabilitas apapun -- pakai default migration.

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testadmin',
            'password'   => 'rahasia123',
        ])->assertOk();

        foreach (['is_superadmin', 'bisa_akses_laporan', 'bisa_atur_hak_akses', 'bisa_lihat_log', 'bisa_input_setoran_semua_loket', 'bisa_edit_setoran_semua_loket'] as $field) {
            $response->assertJsonPath("data.user.{$field}", false);
        }
    }
}

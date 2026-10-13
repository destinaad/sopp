<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Chunk: Atur Password (PUT /api/v1/me/password).
 *
 * Ikut dokumen keputusan: bukan "ganti password" konvensional (gak ada
 * verifikasi password lama), superadmin diblokir total, sesi lain TIDAK
 * di-invalidate, password sama dengan sebelumnya tetap lolos, dan ada
 * proteksi klik dobel (mirip tolakJikaEntryDobel() di SetoranEntryController).
 */
class PengaturanPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(?string $password = null, bool $superadmin = false): User
    {
        $user = $password === null
            ? User::factory()->create(['user_id_sopp' => null])
            : User::factory()->denganPassword($password)->create(['user_id_sopp' => null]);

        if ($superadmin) {
            $user->forceFill(['is_superadmin' => true])->save();
        }

        return $user->fresh();
    }

    public function test_set_password_pertama_kali_sukses(): void
    {
        $user = $this->buatUser(password: null); // password awal NULL
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'rahasiabaru',
            'password_confirmation' => 'rahasiabaru',
        ])->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data', null);

        $this->assertTrue(Hash::check('rahasiabaru', $user->fresh()->password));
    }

    public function test_set_ulang_password_tanpa_password_lama_tetap_sukses(): void
    {
        $user = $this->buatUser(password: 'passwordLama123');
        $this->actingAs($user);

        // Sengaja TIDAK mengirim field password lama sama sekali.
        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordBaru456',
            'password_confirmation' => 'passwordBaru456',
        ])->assertOk()->assertJsonPath('status', 'ok');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('passwordBaru456', $fresh->password));
        $this->assertFalse(Hash::check('passwordLama123', $fresh->password));
    }

    public function test_password_baru_yang_berhasil_diset_bisa_dipakai_login(): void
    {
        $user = $this->buatUser(password: null);
        $user->forceFill(['username' => 'usertest'])->save();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordLoginBaru',
            'password_confirmation' => 'passwordLoginBaru',
        ])->assertOk();

        $this->postJson('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'usertest',
            'password' => 'passwordLoginBaru',
        ])->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_superadmin_ditolak_403(): void
    {
        $superadmin = $this->buatUser(password: 'passwordSuperadmin', superadmin: true);
        $this->actingAs($superadmin);

        $this->putJson('/api/v1/me/password', [
            'password' => 'apapun123',
            'password_confirmation' => 'apapun123',
        ])->assertStatus(403);

        // Password superadmin dipastikan tidak berubah sama sekali.
        $this->assertTrue(Hash::check('passwordSuperadmin', $superadmin->fresh()->password));
    }

    public function test_unauthenticated_ditolak_401(): void
    {
        $this->putJson('/api/v1/me/password', [
            'password' => 'apapun123',
            'password_confirmation' => 'apapun123',
        ])->assertStatus(401);
    }

    public function test_validasi_gagal_password_kosong(): void
    {
        $user = $this->buatUser();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_validasi_gagal_kurang_dari_8_karakter(): void
    {
        $user = $this->buatUser();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'pendek1',
            'password_confirmation' => 'pendek1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_validasi_gagal_konfirmasi_tidak_cocok(): void
    {
        $user = $this->buatUser();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordBenar1',
            'password_confirmation' => 'passwordBeda1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    /**
     * Membuktikan fakta yang sudah diverifikasi ke vendor/laravel/framework:
     * TrimStrings + ConvertEmptyStringsToNull aktif global, jadi password
     * isi spasi doang otomatis gagal 'required' tanpa kode tambahan apapun.
     */
    public function test_validasi_gagal_password_isi_spasi_doang(): void
    {
        $user = $this->buatUser();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => '        ',
            'password_confirmation' => '        ',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_password_baru_sama_dengan_lama_tetap_lolos(): void
    {
        $user = $this->buatUser(password: 'passwordSama123');
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordSama123',
            'password_confirmation' => 'passwordSama123',
        ])->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_klik_dobel_hampir_bersamaan_kena_429(): void
    {
        $user = $this->buatUser();
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordPertama1',
            'password_confirmation' => 'passwordPertama1',
        ])->assertOk();

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordKedua123',
            'password_confirmation' => 'passwordKedua123',
        ])->assertStatus(429)
            ->assertJsonPath('message', 'Tunggu sebentar sebelum menyimpan lagi.');

        // Request kedua yang ditolak beneran gak nyentuh DB sama sekali.
        $this->assertTrue(Hash::check('passwordPertama1', $user->fresh()->password));
    }

    public function test_sesi_tetap_valid_setelah_ganti_password_tidak_ada_invalidasi(): void
    {
        $user = $this->buatUser(password: 'passwordAwal123');
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordSetelahGanti',
            'password_confirmation' => 'passwordSetelahGanti',
        ])->assertOk();

        // Sesi yang sama masih dianggap login, sesuai keputusan: tidak
        // ada invalidasi sesi/token lain setelah password diganti.
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_activity_log_tercatat_tanpa_membocorkan_password(): void
    {
        $user = $this->buatUser(password: null);
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordRahasiaBanget',
            'password_confirmation' => 'passwordRahasiaBanget',
        ])->assertOk();

        $log = ActivityLog::where('user_id', $user->id)
            ->where('jenis_aksi', 'atur_password')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('users', $log->target_type);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame(['password_sebelumnya_kosong' => true], $log->detail_perubahan);

        // Pastikan plaintext ATAU hash password gak pernah nyangkut di
        // kolom log ini dalam bentuk apapun.
        $detailJson = json_encode($log->detail_perubahan);
        $this->assertStringNotContainsString('passwordRahasiaBanget', $detailJson);
        $this->assertStringNotContainsString($user->fresh()->password, $detailJson);
    }

    public function test_activity_log_flag_false_kalau_set_ulang_password(): void
    {
        $user = $this->buatUser(password: 'passwordLama123');
        $this->actingAs($user);

        $this->putJson('/api/v1/me/password', [
            'password' => 'passwordBaru456',
            'password_confirmation' => 'passwordBaru456',
        ])->assertOk();

        $log = ActivityLog::where('user_id', $user->id)
            ->where('jenis_aksi', 'atur_password')
            ->first();

        $this->assertSame(['password_sebelumnya_kosong' => false], $log->detail_perubahan);
    }
}
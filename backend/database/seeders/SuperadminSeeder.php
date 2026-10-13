<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SOPP_SUPERADMIN_USERNAME');
        $password = env('SOPP_SUPERADMIN_PASSWORD');
        $loket_id = env('SOPP_SUPERADMIN_LOKET_ID', 16);

        if (empty($username)) {
            throw new \RuntimeException(
                'SOPP_SUPERADMIN_USERNAME belum di-set di .env. Seeder dibatalkan'
            );
        }

        if (empty($password)) {
            throw new \RuntimeException(
                'SOPP_SUPERADMIN_PASSWORD belum di-set di .env. Seeder dibatalkan'
            );
        }

        $sudahAda = User::where('is_superadmin', true)->exists();

        if ($sudahAda) {
            $this->command?->info('Superadmin sudah ada, seeder dilewati.');
            return;
        }

        User::withoutEvents(function () use ($username, $password, $loket_id) {
            $user = new User();
            $user->forceFill([
                'user_id_sopp' => null,
                'loket_id'  => $loket_id,
                'nama'  => 'Superadmin',
                'username' => $username,
                'password'  => $password,
                'is_superadmin' => true,
                // SEMUA kolom kapabilitas granular SENGAJA dibiarkan
                // default (false) -- termasuk bisa_akses_laporan,
                // bisa_atur_hak_akses, bisa_lihat_log yang sebelumnya
                // keliru di-set true di sini.
                //
                // "Superadmin bisa semua" TIDAK diimplementasikan dengan
                // menyalakan tiap kolom satu-satu (rapuh, gampang lupa
                // tiap nambah kolom baru -- persis yang barusan kejadian).
                // Itu diimplementasikan di DUA tempat, keduanya bukan di
                // sini:
                // 2. User::kapabilitasUntukApi() -- method terpusat (§3.4),
// dipanggil SsoController::verify() dan nanti /api/me. TIGA
// kapabilitas (bisa_akses_laporan, bisa_atur_hak_akses,
// bisa_lihat_log) di-OR dengan is_superadmin; DUA kapabilitas
// loket-acting (bisa_input_setoran_semua_loket,
// bisa_edit_setoran_semua_loket) SENGAJA TIDAK di-OR --
// superadmin harus benar-benar di-grant eksplisit lewat Hak
// Akses buat dua ability itu, bukan otomatis lolos.
// Kalau nanti nambah kolom kapabilitas baru lagi, yang perlu
// diupdate cuma method itu -- bukan seeder ini.

/*
nanti kamu bikin SsoController/endpoint /api/me, pastikan polanya konsisten begini untuk semua kapabilitas
'bisa_akses_laporan' => $user->is_superadmin || $user->bisa_akses_laporan,
'bisa_atur_hak_akses' => $user->is_superadmin || $user->bisa_atur_hak_akses,
'bisa_lihat_log' => $user->is_superadmin || $user->bisa_lihat_log,
'bisa_input_setoran_semua_loket' => $user->bisa_input_setoran_semua_loket,
'bisa_edit_setoran_semua_loket' => $user->bisa_edit_setoran_semua_loket,
*/
            ])->save();
        });

        $this->command?->info('Pembuatan Superadmin Berhasil');
    }
}

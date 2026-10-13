<?php

namespace App\Policies;

use App\Models\User;

/**
 * Policy method di sini SENGAJA cuma tiga -- createForOtherLoket(),
 * updateForOtherLoket(), dan viewForOtherLoket() -- karena itu
 * satu-satunya ability yang butuh dikecualikan dari bypass
 * Gate::before() global (§3.1).
 *
 * Method CRUD biasa (create, update, view, delete -- buat aksi teller
 * ke setoran miliknya sendiri) BELUM ditulis di sini -- bentuknya
 * belum bisa dipastikan sebelum kontrak endpoint Card A/B final,
 * dan lagipula ability itu memang OTOMATIS bypass buat superadmin
 * lewat Gate::before() (perilaku yang sudah benar), jadi belum
 * mendesak buat ditulis eksplisit sekarang.
 */
class SetoranHarianPolicy
{
    /**
     * Ability buat submit setoran ATAS NAMA loket manapun (dropdown
     * pilih cabang -- §7.4, dipakai Bu Wiwik). SENGAJA TANPA OR ke
     * is_superadmin (§3.4) -- superadmin default TIDAK BOLEH,
     * kecuali kolomnya beneran di-grant true lewat Hak Akses.
     */
    public function createForOtherLoket(User $user): bool
    {
        return (bool) $user->bisa_input_setoran_semua_loket;
    }

    /**
     * Ability buat edit/hapus setoran loket manapun. Sama seperti
     * di atas -- SENGAJA TANPA OR ke is_superadmin.
     */
    public function updateForOtherLoket(User $user): bool
    {
        return (bool) $user->bisa_edit_setoran_semua_loket;
    }

    /**
     * Ability buat VIEW read-only (bukan edit) setoran orang lain --
     * dipakai Detail Setoran GET doang (authorizeView()). Union dari 2
     * kapabilitas: siapapun yang boleh EDIT lintas-loket otomatis boleh
     * VIEW juga (edit mensyaratkan bisa lihat lebih dulu), DITAMBAH
     * bisa_akses_laporan (Kontrak Laporan §5 -- verifikator laporan
     * butuh navigasi read-only dari Laporan ke Detail Setoran, TANPA
     * otomatis dapat hak edit).
     */
    public function viewForOtherLoket(User $user): bool
    {
        return (bool) $user->bisa_edit_setoran_semua_loket || (bool) $user->bisa_akses_laporan;
    }
}
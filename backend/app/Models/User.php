<?php
// =====================================================================
// FILE: app/Models/User.php
// =====================================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * FIX: kolom kapabilitas (bisa_akses_laporan, bisa_atur_hak_akses,
     * bisa_lihat_log) SENGAJA TIDAK dimasukkan ke $fillable, walau ada
     * di tabel. Ini kolom yang menentukan hak akses -- kalau ikut
     * fillable, endpoint apapun yang ceroboh pakai $request->all()
     * bisa dipakai user biasa buat menaikkan privilege dirinya sendiri.
     * is_superadmin juga tidak pernah ada di sini (dan tidak akan
     * pernah, sesuai keputusan: cuma lewat seeder/tinker).
     *
     * Kalau HakAksesController perlu update kapabilitas user lain,
     * lakukan eksplisit per-kolom, bukan mass assignment:
     *   $user->bisa_akses_laporan = $request->boolean('bisa_akses_laporan');
     *   $user->save();
     * dan wajib digate lewat policy/middleware bisa_atur_hak_akses
     * di controller-nya, bukan cukup andalkan $fillable kosongnya kolom ini.
     */
    protected $fillable = [
        'user_id_sopp',
        'loket_id',
        'nama',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bisa_akses_laporan'  => 'boolean',
            'bisa_atur_hak_akses' => 'boolean',
            'bisa_lihat_log'      => 'boolean',
            'bisa_input_setoran_semua_loket' => 'boolean',
            'bisa_edit_setoran_semua_loket' => 'boolean',
            'is_superadmin'       => 'boolean',
            'password'            => 'hashed',
        ];
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function setoranHarian(): HasMany
    {
        return $this->hasMany(SetoranHarian::class, 'user_id');
    }

    /**
     * True kalau row ini berasal dari auto-provisioning SSO SOPP lama
     * (teller ATAU privileged user seperti Bu Wiwik -- dua-duanya
     * sama-sama punya user_id_sopp terisi, cuma kapabilitasnya beda).
     * False untuk superadmin generik, yang user_id_sopp-nya NULL
     * dan dibuat manual lewat seeder, independen dari SOPP lama.
     */
    public function isTellerSopp(): bool
    {
        return ! $this->is_superadmin && ! is_null($this->user_id_sopp);
    }

    public function kapabilitasUntukApi(): array
    {
        return [
            'is_superadmin'                  => (bool) $this->is_superadmin,
            'bisa_akses_laporan'             => $this->is_superadmin || $this->bisa_akses_laporan,
            'bisa_atur_hak_akses'            => $this->is_superadmin || $this->bisa_atur_hak_akses,
            'bisa_lihat_log'                 => $this->is_superadmin || $this->bisa_lihat_log,
            'bisa_input_setoran_semua_loket' => (bool) $this->bisa_input_setoran_semua_loket,
            'bisa_edit_setoran_semua_loket'  => (bool) $this->bisa_edit_setoran_semua_loket,
        ];
    }
}

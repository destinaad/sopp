<?php
// =====================================================================
// FILE: app/Models/ActivityLog.php
// =====================================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'jenis_aksi',
        'target_type',
        'target_id',
        'detail_perubahan',
        'actor_loket_id',
        'actor_role',
        'target_loket_id',
    ];

    protected function casts(): array
    {
        return [
            'detail_perubahan' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Loket milik TARGET (data yang kena aksi) -- dipakai buat resolve
     * field `loket` di response Log Aktivitas tanpa query manual di
     * controller. Lihat turunkanTargetLoketId() soal gimana kolom ini diisi.
     */
    public function targetLoket()
    {
        return $this->belongsTo(Loket::class, 'target_loket_id', 'loket_id');
    }

    /**
     * Catatan: pemanggilan ini sebaiknya SELALU dibungkus DB::transaction()
     * bareng aksi utamanya di controller (submit setoran, ubah kapabilitas,
     * dst) -- supaya tidak ada state "aksi berhasil tapi log gagal tercatat".
     * Ini tanggung jawab controller/observer, bukan sesuatu yang bisa
     * dipaksakan dari dalam model ini sendiri.
     *
     * Nerima objek User $actor (bukan cuma int $userId) SENGAJA -- supaya
     * actor_loket_id dan actor_role bisa diturunkan otomatis dari kondisi
     * user SAAT ITU JUGA (§2.4: data beku, bukan live-join), tanpa tiap
     * pemanggil harus menghitung sendiri role-nya manual (itu pola yang
     * sudah kejadian salah sekali di kapabilitasUntukApi(), lihat §12 --
     * jangan diulang di sini).
     */
    public static function catat(
        User $actor,
        string $jenisAksi,
        ?string $objectType = null,
        ?int $objectId = null,
        ?array $detailPerubahan = null
    ): self {
        return static::create([
            'user_id'          => $actor->id,
            'jenis_aksi'       => $jenisAksi,
            'target_type'      => $objectType,
            'target_id'        => $objectId,
            'detail_perubahan' => $detailPerubahan,
            'actor_loket_id' => $actor->loket_id,
            'actor_role' => self::turunkanActorRole($actor),
            'target_loket_id' => self::turunkanTargetLoketId($objectType, $objectId, $detailPerubahan),
        ]);
    }

     /**
     * §2.4: is_superadmin -> 'superadmin'; salah satu dari 5 kapabilitas
     * lain true -> 'verifikator'; selain itu -> 'teller'. SATU tempat
     * doang logic ini boleh ada -- jangan ditulis ulang di controller.
     */
    private static function turunkanActorRole(User $actor): string
    {
        if ($actor->is_superadmin) {
            return 'superadmin';
        }

        if ($actor->bisa_akses_laporan || 
            $actor->bisa_atur_hak_akses ||
            $actor->bisa_lihat_log ||
            $actor->bisa_input_setoran_semua_loket ||
            $actor->bisa_edit_setoran_semua_loket) {
            return 'verifikator';
        }

        return 'teller';
    }

    /**
     * Loket milik TARGET (data yang kena aksi) -- BUKAN loket pelaku
     * (itu actor_loket_id di atas). Dibekukan di sini, saat kejadian,
     * supaya tetap akurat walau row targetnya sendiri belakangan
     * dihapus permanen (misal hapus_setoran / setoran_dihapus).
     * Dipakai buat kolom `loket` di Log Aktivitas -- lihat
     * spek-log-aktivitas-admin.md Bagian 2.4.
     *
     * SENGAJA seluruhnya di dalam method ini (bukan parameter baru di
     * catat(), bukan logic di controller) -- $objectType/$objectId/
     * $detailPerubahan yang sudah dikirim tiap pemanggil catat() itu
     * cukup buat derive ini semua, jadi tidak ada satupun controller
     * existing yang perlu diubah gara-gara kolom baru ini.
     */
    private static function turunkanTargetLoketId(
        ?string $objectType,
        ?int $objectId,
        ?array $detailPerubahan
    ): ?int {
        return match ($objectType) {
            'setoran_harian' => $objectId !== null
                ? SetoranHarian::find($objectId)?->loket_id
                : null,

            // setoran_detail tidak punya loket_id sendiri -- harus lewat
            // setoran_harian induknya. ID induk itu SELALU ada di
            // detail_perubahan['setoran_harian_id'], sudah diisi di semua
            // pemanggilan buat_setoran/edit_setoran/hapus_setoran yang ada.
            'setoran_detail' => isset($detailPerubahan['setoran_harian_id'])
                ? SetoranHarian::find($detailPerubahan['setoran_harian_id'])?->loket_id
                : null,

            'users' => $objectId !== null
                ? User::find($objectId)?->loket_id
                : null,

            default => null, // aksi system-wide (target_type null), atau tipe lain di luar 3 ini
        };
    }
}
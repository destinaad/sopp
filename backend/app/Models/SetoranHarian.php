<?php
// =====================================================================
// FILE: app/Models/SetoranHarian.php
//
// PERUBAHAN vs versi sebelumnya (hasil diskusi audit shared logic #3,
// dipicu dari Dashboard Teller tapi fix-nya di titik pusat/model):
//
//   1. selisih() sekarang dibandingkan ke total setor GABUNGAN semua
//      teller di loket+tanggal yang sama (bukan cuma row ini) --
//      method baru totalSetorUntukLoketTanggal() di bawah.
//   2. Method baru itu HANYA menghitung row berstatus 'submitted' --
//      draft (termasuk milik user yang sedang login sendiri) SENGAJA
//      tidak ikut. Lihat komentar di totalSetorUntukLoketTanggal()
//      buat alasan lengkapnya.
//
// TIDAK diubah sengaja: statusLabel() di bawah masih punya null-bug
// yang sama kayak yang kita benerin di DashboardTellerController
// (isLunas() === false ketika sebenarnya "belum diketahui", bukan
// "belum lunas"). Ini SENGAJA tidak disentuh sekarang -- scope
// audit ini cuma Dashboard Teller, dan statusLabel() gak dipanggil
// di manapun di codebase saat ini (sudah dicek, tidak ada
// pemanggilnya). Kalau nanti ada chunk yang mulai makai method ini,
// WAJIB di-guard dulu persis kayak pola di riwayatTerakhir() sebelum
// dipakai -- jangan asumsikan method ini sudah aman.
// =====================================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SetoranHarian extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'setoran_harian';

    protected $fillable = [
        'user_id',
        'loket_id',
        'tanggal_transaksi',
        'snapshot_sopp',
        'status',
        'submitted_at',
        'entries_updated_at',
        'dibuat_via_bantuan_pelunasan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_transaksi' => 'date:Y-m-d',
            'snapshot_sopp'     => 'array',
            'submitted_at'      => 'datetime',
            'entries_updated_at' => 'datetime',
            'dibuat_via_bantuan_pelunasan' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function loket(): BelongsTo
    {
        return $this->belongsTo(Loket::class, 'loket_id', 'loket_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SetoranDetail::class, 'setoran_harian_id');
    }

    /**
     * "Loket Sudah Setor" versi per-row (instance ini punya minimal
     * satu entry). Ini BEDA dari cek "loket sudah setor" di level
     * Dashboard Bu Wiwik, yang scope-nya lintas semua user di loket
     * itu -- itu query terpisah di controller/repository, bukan di sini.
     */
    public function sudahSetor(): bool
    {
        return $this->entries()->exists();
    }

    /**
     * Total pendapatan versi SOPP lama, diambil dari snapshot yang
     * sudah di-fetch dari bridge. Akses defensif -- kalau snapshot
     * null atau field grand_total kebetulan tidak ada (misal snapshot
     * lama dari sebelum ada perubahan struktur bridge), fallback 0,
     * BUKAN exception yang bisa menjatuhkan seluruh request.
     */
    public function totalPendapatanSopp(): int
    {
        return (int) ($this->snapshot_sopp['grand_total'] ?? 0);
    }

    /**
     * Total PERSONAL row ini doang (punya 1 user, 1 loket, 1 tanggal).
     * TIDAK diubah oleh fix shared logic #3 -- ini TETAP dipakai di
     * mana pun butuh angka "punya saya sendiri" (misal prefill form
     * Card A, breakdown per-metode di Riwayat Setoran). Jangan
     * ketuker sama totalSetorUntukLoketTanggal() di bawah, dua-duanya
     * sengaja dibiarkan terpisah karena artinya beda.
     */
    public function totalSetor(): int
    {
        return (int) $this->entries()->sum('nominal');
    }

    /**
     * Total setor GABUNGAN semua teller di loket+tanggal yang sama --
     * bukan cuma milik 1 row. Static (bukan instance) supaya bisa
     * dipanggil walau row user yang login belum ada sama sekali.
     *
     * KENAPA filter status='submitted': draft itu progress yang masih
     * bisa diubah/dihapus kapan saja (belum resmi/final) -- kalau
     * ikut dihitung di angka pembanding ini, ada 2 masalah:
     *   (a) row yang SUDAH submitted jadi selisih-nya "goyang" gara-
     *       gara draft teller LAIN yang masih berubah-ubah, padahal
     *       row yang submitted itu sendiri sudah dikunci;
     *   (b) teller sendiri bisa salah baca dashboard-nya sebagai
     *       "sudah setor" padahal dia belum pernah pencet submit --
     *       ini alasan bisnis utama yang dikonfirmasi user, bukan
     *       cuma soal konsistensi kode.
     * Draft TIDAK dikecualikan berdasar siapa pemiliknya -- berlaku
     * rata untuk row siapapun, termasuk user yang sedang login.
     */
    public static function totalSetorUntukLoketTanggal(int $loketId, string $tanggal): int
    {
        // SESUDAH
        return (int) SetoranDetail::whereHas(
            'setoranHarian',
            fn($q) => $q
                ->where('loket_id', $loketId)
                ->where('tanggal_transaksi', $tanggal)
                ->where('status', 'submitted')
        )->sum('nominal');
    }

    /**
     * Selisih = pendapatan versi SOPP lama (angka LOKET, bukan
     * per-user) dikurangi total setor GABUNGAN semua teller yang
     * SUDAH SUBMIT di loket+tanggal yang sama.
     *
     * ALASAN (shared logic #3, dikonfirmasi ke kode sistem lama):
     * bridge/pendapatan_sopp tidak punya konsep pemisahan per-
     * petugas -- laporan sistem lama sendiri (sopp_report_date_
     * loket.php) SUM(trans_amount) di-GROUP BY trans_loket_id doang,
     * TANPA trans_user_id sama sekali. Kalau selisih ini dibandingkan
     * ke total_setor 1 user doang (versi sebelumnya), tiap teller
     * yang berbagi loket+tanggal yang sama bisa keliatan "belum
     * lunas" TERUS-TERUSAN walau gabungan setoran mereka sebenarnya
     * sudah pas persis sama pendapatan loket. Perbandingannya wajib
     * apple-to-apple: loket vs loket, bukan loket vs 1 orang.
     */
    public function selisih(): ?int
    {
        if ($this->snapshot_sopp === null) {
            return null;
        }
        return $this->totalPendapatanSopp() - self::totalSetorUntukLoketTanggal(
            $this->loket_id,
            $this->tanggal_transaksi->toDateString(),
        );
    }

    public function isLunas(): ?bool
{
    $selisih = $this->selisih();
    return $selisih === null ? null : $selisih === 0;
}

    // PERINGATAN: masih punya null-bug yang sama kayak yang dibenerin
    // di DashboardTellerController::riwayatTerakhir() -- BELUM
    // dibenerin di sini karena method ini gak dipanggil di manapun
    // saat ini (dicek via grep, 0 pemanggil di luar file ini). Kalau
    // mau dipakai nanti, guard dulu $this->snapshot_sopp !== null
    // sebelum manggil ini, jangan pakai apa adanya.
    public function statusLabel(): string
    {
        return $this->isLunas() ? 'Lunas' : 'Belum Lunas';
    }

    public function jumlahEntriBelumKonfirmasi(): int
    {
        return $this->entries()->where('is_konfirmasi', false)->count();
    }
}
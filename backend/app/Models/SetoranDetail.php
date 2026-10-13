<?php
// =====================================================================
// FILE: app/Models/SetoranDetail.php
// =====================================================================
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetoranDetail extends Model
{
    use HasFactory;

    protected $table = 'setoran_detail';

    protected $fillable = [
        'setoran_harian_id',
        'metode_pembayaran',
        'nominal',
        'foto_bukti_path',
        'is_konfirmasi',
        'keterangan',
        'nama_karyawan',
        'identitas_karyawan', // karena bs diisi 2NIK atau NIP jadi nama dikasih yg generik, lihat catatan migration
    ];

    protected function casts(): array
    {
        return [
            'nominal'       => 'integer',
            'is_konfirmasi' => 'boolean',
        ];
    }

    public function setoranHarian(): BelongsTo
    {
        return $this->belongsTo(SetoranHarian::class, 'setoran_harian_id');
    }

    public function butuhFotoBukti(): bool
    {
        return $this->metode_pembayaran !== 'kpay';
    }

    /**
     * SATU-SATUNYA tempat yang boleh mendefinisikan bentuk 1 entry
     * setoran_detail di response API. Dipakai bareng oleh Card A,
     * Detail Setoran, dan Pelunasan -- supaya field baru (atau
     * perbaikan kayak foto_bukti_url di bawah) otomatis konsisten di
     * semua tempat, tidak perlu diingat-ingat untuk ditempel manual
     * satu-satu lagi.
     *
     * foto_bukti_url dibangun lewat route() (bukan url() dengan string
     * path di-hardcode) supaya tidak lagi gampang meleset kalau prefix
     * routing berubah -- sebelumnya ada versi hardcode yang kelewat
     * prefix "v1" sehingga URL yang dihasilkan sebenarnya 404.
     */
    public function toApiShape(): array
    {
        return [
            'id' => $this->id,
            'metode_pembayaran' => $this->metode_pembayaran,
            'keterangan' => $this->keterangan,
            'nominal' => $this->nominal,
            'foto_bukti_url' => $this->foto_bukti_path
                ? route('bukti-setoran.show', ['path' => $this->foto_bukti_path])
                : null,
            'is_konfirmasi' => $this->is_konfirmasi,
            'nama_karyawan' => $this->nama_karyawan,
            'identitas_karyawan' => $this->identitas_karyawan,
        ];
    }
}
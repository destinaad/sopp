<?php

namespace App\Services;

use App\Exceptions\BridgeException;
use App\Models\SetoranHarian;
use Illuminate\Support\Collection;

/**
 * Hitung "pendapatan & selisih milik 1 teller sendiri" (beda dari
 * SetoranHarian::selisih()/totalPendapatanSopp() yang loket-wide),
 * sumbernya BridgeService::fetchDetailPerUser().
 *
 * SENGAJA TERPISAH dari SetoranHarian::selisih() -- method itu (shared
 * logic #3) tetap dipakai apa adanya di Card A/Dashboard/Pelunasan,
 * TIDAK diubah sama sekali. Class ini murni tambahan buat Detail
 * Setoran & Riwayat.
 *
 * PENTING soal biaya: SetoranHarian::selisih() itu GRATIS (baca kolom
 * snapshot_sopp yang sudah di-cache, gak ada network call). Method di
 * class ini SEBALIKNYA -- selalu live-fetch ke bridge.php lewat HTTP.
 * JANGAN dipanggil di jalur yang scope-nya gak dibatasi tanggal/jumlah
 * row (all-time, unbounded) -- lihat diskusi soal pendingSelisih() dan
 * export() personal yang sengaja BELUM dipasangi ini sampai ada
 * keputusan soal pembatasan rentangnya.
 */
class SelisihPerUserService
{
    public function __construct(private readonly BridgeService $bridge) {}

    /**
     * Buat 1 row (Detail Setoran). $row->user WAJIB sudah di-load
     * (eager load), dan minimal berisi kolom user_id_sopp +
     * is_superadmin (dipakai isTellerSopp()).
     *
     * Ketiga field di return SELALU gagal BARENGAN (satu sumber
     * kegagalan yang sama -- fetch bridge ini), BUKAN 3 kondisi gagal
     * independen kayak sebelumnya (snapshot_sopp null vs bridge gagal
     * itu 2 hal beda dulu, sekarang cuma 1: fetch ini).
     *
     * @return array{selisih_sendiri: ?int, pendapatan: ?list<array{vendor_id:mixed,vendor_name:string,kategori:string,total:int}>, total_pendapatan: ?int}
     */
    public function untukSatuRow(SetoranHarian $row): array
    {
        if (! $row->user->isTellerSopp()) {
            // Superadmin generik (satu-satunya kasus user_id_sopp null
            // di codebase ini) -- gak punya identitas legacy sama
            // sekali buat dicocokkan ke bridge. Bukan error, memang
            // gak ada apa-apa buat dibandingkan.
            return $this->kosong();
        }

        $tanggal = $row->tanggal_transaksi->toDateString();

        try {
            $hasil = $this->bridge->fetchDetailPerUser($tanggal, $tanggal, $row->loket_id);
        } catch (BridgeException $e) {
            \Log::warning('Gagal fetch bridge detail per user untuk satu row', [
                'loket_id' => $row->loket_id,
                'rentang_tanggal' => [$tanggal, $tanggal],
                'pesan' => $e->getMessage(),
            ]);
            // Konsisten sama filosofi gagal_dimuat yang sudah ada di
            // seluruh sistem ini -- gagal fetch bukan alasan buat
            // nge-500 seluruh halaman Detail Setoran/Riwayat, cukup
            // field-field ini null.
            return $this->kosong();
        }

        return $this->ringkasUntukRow($hasil['data'], $row);
    }

    /**
     * Buat banyak row sekaligus (Riwayat index()) -- diminimalkan
     * jumlah request bridge-nya: dikelompokkan per loket_id, lalu 1x
     * fetch per grup pakai rentang tanggal min..max dari row-row yang
     * ada di grup itu (BUKAN 1x fetch per row).
     *
     * PERINGATAN: rentang min..max ini TIDAK dibatasi lagi di sini --
     * kalau $rows yang masuk sudah mencakup rentang tanggal yang lebar
     * (misal dari query all-time tanpa filter), method ini akan nyoba
     * fetch rentang selebar itu juga. Pemanggil WAJIB memastikan $rows
     * yang dikirim ke sini sudah dibatasi ke rentang yang wajar
     * (misal 1 halaman pagination, atau filter tanggal eksplisit) --
     * lihat catatan class-level soal pendingSelisih()/export().
     *
     * $rows: Collection<SetoranHarian>, tiap row->user WAJIB sudah
     * di-load (eager load) berisi user_id_sopp + is_superadmin.
     *
     * @return array<int, array{selisih_sendiri: ?int, pendapatan: ?list<array<string,mixed>>, total_pendapatan: ?int}> keyed by SetoranHarian->id
     */
    public function untukBanyakRow(Collection $rows): array
    {
        $hasilPerRow = [];

        foreach ($rows->groupBy('loket_id') as $loketId => $rowsDiLoketIni) {
            $tanggalMulai = $rowsDiLoketIni->min(fn(SetoranHarian $r) => $r->tanggal_transaksi->toDateString());
            $tanggalSelesai = $rowsDiLoketIni->max(fn(SetoranHarian $r) => $r->tanggal_transaksi->toDateString());

            try {
                $dataBridge = $this->bridge->fetchDetailPerUser($tanggalMulai, $tanggalSelesai, (int) $loketId)['data'];
            } catch (BridgeException $e) {
                \Log::warning('Gagal fetch bridge detail per user untuk banyak row', [
                    'loket_id' => (int) $loketId,
                    'rentang_tanggal' => [$tanggalMulai, $tanggalSelesai],
                    'pesan' => $e->getMessage(),
                ]);
                $dataBridge = null; // null = gagal fetch, bukan "kosong beneran"
            }

            foreach ($rowsDiLoketIni as $row) {
                if ($dataBridge === null || ! $row->user->isTellerSopp()) {
                    $hasilPerRow[$row->id] = $this->kosong();
                    continue;
                }
                $hasilPerRow[$row->id] = $this->ringkasUntukRow($dataBridge, $row);
            }
        }

        return $hasilPerRow;
    }

    private function kosong(): array
    {
        return ['selisih_sendiri' => null, 'pendapatan' => null, 'total_pendapatan' => null];
    }

    /**
     * @param list<array<string,mixed>> $dataBridge hasil mentah fetchDetailPerUser (belum difilter per-user)
     * @return array{selisih_sendiri: int, pendapatan: list<array<string,mixed>>, total_pendapatan: int}
     */
    private function ringkasUntukRow(array $dataBridge, SetoranHarian $row): array
    {
        $tanggal = $row->tanggal_transaksi->toDateString();

        $milikDia = collect($dataBridge)->filter(
            fn(array $r) => (string) ($r['user_id'] ?? '') === (string) $row->user->user_id_sopp
                && (string) ($r['tanggal'] ?? '') === $tanggal
        );

        // Groupby vendor -- niru PERSIS cara bridge sendiri ngebentuk
        // 'kategori' di mode default (GROUP_CONCAT nama produk per
        // vendor, dipisah ', '), cuma di sini dihitung ulang di level
        // PERSONAL (data yang di-groupby udah difilter ke user ini
        // doang), bukan diambil dari kolom kategori bridge yang mentah
        // (itu masih granular per-produk, belum di-agregat per-vendor).
        $pendapatan = $milikDia
            ->groupBy('vendor_id')
            ->map(function (Collection $rowsVendor) {
                $pertama = $rowsVendor->first();
                return [
                    'vendor_id' => $pertama['vendor_id'],
                    'vendor_name' => $pertama['vendor_name'],
                    'kategori' => $rowsVendor->pluck('produk_name')->filter()->unique()->values()->implode(', '),
                    'total' => (int) $rowsVendor->sum('total'),
                ];
            })
            ->values()
            ->all();

        $totalPendapatan = (int) $milikDia->sum('total');

        return [
            'selisih_sendiri' => $totalPendapatan - $row->totalSetor(),
            'pendapatan' => $pendapatan,
            'total_pendapatan' => $totalPendapatan,
        ];
    }
}
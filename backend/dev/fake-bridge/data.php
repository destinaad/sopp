<?php
/**
 * DATA DUMMY BERSAMA -- dipakai oleh bridge.php (server SOPP lama palsu)
 * DAN DummyLaporanSeeder (data lokal), supaya angka keduanya SELALU cocok.
 * Deterministik: hasil sama tiap kali dijalankan (berdasar crc32, bukan rand()).
 * HANYA UNTUK DEV/TESTING. Jangan dipakai di production.
 */

function dummy_lokets(): array
{
    return [
        1 => 'SOPP Blimbing', 2 => 'SOPP Malang Kota', 3 => 'SOPP Gadang', 4 => 'SOPP Batu',
        5 => 'SOPP Bululawang', 6 => 'SOPP Karangploso', 7 => 'SOPP Klojen', 8 => 'SOPP Lawang',
        9 => 'SOPP Pakis', 10 => 'SOPP Sawojajar', 11 => 'SOPP Singosari', 12 => 'SOPP Tumpang',
        13 => 'SOPP Batu STO', 14 => 'SOPP Araya', 15 => 'SOPP Drive Thru', 16 => 'Administrasi Jastel',
    ];
}

function dummy_vendors(): array
{
    return [1 => 'Alfamart', 2 => 'Indomaret', 3 => 'Pos Indonesia'];
}

/** 2 teller per loket (1-15). user_id_sopp = identitas di sistem lama. */
function dummy_tellers(int $loketId): array
{
    $nama = dummy_lokets()[$loketId] ?? "Loket $loketId";
    $short = str_replace('SOPP ', '', $nama);
    return [
        ['user_id' => sprintf('9%02d01', $loketId), 'nama' => "Teller $short 1"],
        ['user_id' => sprintf('9%02d02', $loketId), 'nama' => "Teller $short 2"],
    ];
}

/** Tanggal yang SENGAJA dipaksa aktif untuk loket 1 & 2 (kebutuhan skenario Laporan). */
function dummy_paksa(): array
{
    return [
        1 => ['2026-09-15', '2026-09-30', '2026-10-01'],
        2 => ['2026-09-15'],
    ];
}

function dummy_hash(string $s): int
{
    return crc32($s);
}

function dummy_loket_aktif(string $tanggal, int $loketId): bool
{
    if ($tanggal > date('Y-m-d') || $tanggal < '2026-01-01') {
        return false;
    }
    if (in_array($tanggal, dummy_paksa()[$loketId] ?? [], true)) {
        return true;
    }
    $persen = $loketId <= 4 ? 85 : 45;
    return dummy_hash("aktif|$tanggal|$loketId") % 100 < $persen;
}

function dummy_teller_aktif(string $tanggal, int $loketId, int $idx): bool
{
    if (! dummy_loket_aktif($tanggal, $loketId)) {
        return false;
    }
    if ($idx === 0) {
        return true;
    }
    if ($loketId === 1 && $tanggal === '2026-09-15') {
        return true;                       // Teller 1 & 2 loket 1 sama-sama aktif
    }
    if ($loketId === 2 && $tanggal === '2026-09-15') {
        return false;                      // loket 2 cukup 1 teller
    }
    if (in_array($tanggal, dummy_paksa()[$loketId] ?? [], true)) {
        return false;                      // tanggal paksa lain: cuma teller 1
    }
    return dummy_hash("t2|$tanggal|$loketId") % 100 < 50;
}

function dummy_nominal(string $tanggal, int $loketId, int $vendorId, string $userId): int
{
    return (dummy_hash("nom|$tanggal|$loketId|$vendorId|$userId") % 40 + 10) * 10000; // 100rb-490rb
}

/** Total transaksi satu teller di satu loket+tanggal (jumlah semua vendor). */
function dummy_total_teller(string $tanggal, int $loketId, int $idx): int
{
    if (! dummy_teller_aktif($tanggal, $loketId, $idx)) {
        return 0;
    }
    $uid = dummy_tellers($loketId)[$idx]['user_id'];
    $t = 0;
    foreach (array_keys(dummy_vendors()) as $v) {
        $t += dummy_nominal($tanggal, $loketId, $v, $uid);
    }
    return $t;
}

/**
 * Baris persis seperti respons bridge.php.
 * detail=false -> mode default (per tanggal+loket+vendor), detail=true -> detail_rekonsiliasi (+user).
 */
function dummy_rows(string $mulai, string $selesai, ?int $loketId, bool $detail): array
{
    $rows = [];
    $lokets = $loketId ? [$loketId] : range(1, 15);
    for ($d = strtotime($mulai); $d <= strtotime($selesai); $d += 86400) {
        $tgl = date('Y-m-d', $d);
        foreach ($lokets as $l) {
            foreach (dummy_vendors() as $vid => $vname) {
                $perUser = [];
                foreach (dummy_tellers($l) as $idx => $t) {
                    if (dummy_teller_aktif($tgl, $l, $idx)) {
                        $perUser[] = [$t, dummy_nominal($tgl, $l, $vid, $t['user_id'])];
                    }
                }
                if (! $perUser) {
                    continue;
                }
                if ($detail) {
                    foreach ($perUser as [$t, $n]) {
                        $rows[] = ['tanggal' => $tgl, 'loket_id' => $l, 'vendor_id' => $vid, 'vendor_name' => $vname,
                            'total' => $n, 'user_id' => $t['user_id'], 'user_name' => $t['nama']];
                    }
                } else {
                    $rows[] = ['tanggal' => $tgl, 'loket_id' => $l, 'vendor_id' => $vid, 'vendor_name' => $vname,
                        'total' => array_sum(array_column($perUser, 1))];
                }
            }
        }
    }
    return $rows;
}

<?php
/**
 * SERVER SOPP LAMA PALSU (dev only).
 * Jalankan dari folder backend:  php -S 127.0.0.1:9000 -t dev/fake-bridge
 * .env backend:  SOPP_BRIDGE_URL=http://127.0.0.1:9000/bridge.php   SOPP_BRIDGE_KEY=dummy-key
 */
require __DIR__ . '/data.php';

header('Content-Type: application/json');
$out = fn (array $a) => print json_encode($a);

if (($_SERVER['HTTP_X_BRIDGE_KEY'] ?? '') !== 'dummy-key') {
    $out(['status' => 'error', 'message' => 'Key tidak valid']);
    return;
}

$mode = $_GET['mode'] ?? 'default';

if ($mode === 'master_loket') {
    $data = [];
    foreach (dummy_lokets() as $id => $nama) {
        $data[] = ['loket_id' => $id, 'loket_name' => $nama];
    }
    $out(['status' => 'ok', 'data' => $data]);
    return;
}

$mulai = $_GET['tanggal_mulai'] ?? null;
$selesai = $_GET['tanggal_selesai'] ?? null;
if (! $mulai || ! $selesai || $selesai < $mulai) {
    $out(['status' => 'error', 'message' => 'tanggal_mulai/tanggal_selesai tidak valid']);
    return;
}
if ((strtotime($selesai) - strtotime($mulai)) / 86400 + 1 > 31) {
    $out(['status' => 'error', 'message' => 'Rentang maksimal 31 hari']);
    return;
}

$loket = isset($_GET['loket_id']) ? (int) $_GET['loket_id'] : null;
$rows = dummy_rows($mulai, $selesai, $loket, $mode === 'detail_rekonsiliasi');
$out(['status' => 'ok', 'data' => $rows, 'grand_total' => array_sum(array_column($rows, 'total'))]);

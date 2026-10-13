<?php

namespace App\Exceptions;

use Exception;

/**
 * Dilempar oleh BridgeService kalau bridge.php tidak bisa dihubungi
 * sama sekali (WAMP mati, timeout, dll) ATAU bridge merespons
 * dengan status "error" (key salah, format tanggal salah, dst).
 *
 * Controller yang menangkap ini WAJIB menampilkan pesan eksplisit
 * ke pengguna ("Data pendapatan tidak dapat dimuat saat ini") --
 * BUKAN diam-diam menampilkan angka 0, sesuai skenario ketahanan
 * sistem yang sudah disepakati (0 bisa disalahartikan sebagai
 * "memang tidak ada transaksi").
 */
class BridgeException extends Exception
{
    //
}
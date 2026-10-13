<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limit tombol Refresh (§8.5, key WAJIB termasuk tanggal). Window
 * 10 detik DIKONFIRMASI user sebagai default final (sebelumnya belum
 * diputuskan eksplisit di sumber kebenaran, sekarang bukan lagi
 * sekadar "default wajar").
 *
 * $detik bisa di-override per pemanggil (default 10 dipertahankan
 * biar pemanggil lama -- SetoranEntryController, PelunasanController,
 * LaporanController -- gak perlu diubah). Dashboard Admin manggil
 * dengan 20 detik eksplisit (bagian 4 dokumen desainnya) -- angka ini
 * juga sudah DIKONFIRMASI user sebagai keputusan final, bukan asumsi.
 */
trait ThrottlesBridgeRefresh
{
    private function tolakJikaKenaRateLimit(string $key, int $detik = 10): void
    {
        if (! RateLimiter::attempt($key, 1, fn () => true, $detik)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Tunggu beberapa detik sebelum refresh lagi.',
                'errors' => (object) [],
            ], 429));
        }
    }
}
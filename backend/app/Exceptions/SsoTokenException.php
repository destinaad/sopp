<?php

namespace App\Exceptions;

use Exception;

/**
 * Dilempar oleh SsoTokenService kalau token SSO gagal diverifikasi
 * di titik manapun -- gagal decrypt, signature tidak cocok, sudah
 * kadaluarsa, atau sudah pernah dipakai.
 *
 * Sengaja SATU jenis exception buat semua kegagalan verifikasi
 * (bukan exception terpisah per jenis kegagalan) -- controller cuma
 * perlu tau "verifikasi gagal, tampilkan pesannya", tidak perlu
 * bercabang logic berbeda tergantung jenis kegagalannya.
 *
 * Isi pesan TIDAK seragam -- lihat sumber kebenaran §5.5:
 * - Kegagalan kriptografis (decrypt gagal, signature tidak cocok,
 *   format rusak): digenerikkan jadi "Token tidak valid." -- tidak
 *   membocorkan detail teknis mana yang gagal.
 * - Token kadaluarsa / sudah pernah dipakai: pesan SPESIFIK dan
 *   actionable ("Token sudah kadaluarsa, silakan ulangi dari SOPP."
 *   / "Token sudah pernah digunakan.") -- dua kondisi ini murni
 *   soal waktu/replay, bukan soal tebak-menebak isi kunci
 *   kriptografi, jadi aman ditampilkan spesifik ke pengguna.
 * SsoTokenService sudah menerapkan pembagian ini dengan benar --
 * catatan ini cuma penanda kenapa pesannya boleh langsung
 * ditampilkan ke pengguna apa adanya oleh controller.
 */
class SsoTokenException extends Exception
{
    //
}
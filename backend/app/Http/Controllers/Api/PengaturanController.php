<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PUT /v1/me/password (auth:sanctum + throttle:5,1).
 *
 * BUKAN "ganti password" konvensional -- gak ada verifikasi password
 * lama sama sekali. Password sebelumnya hampir selalu NULL (teller
 * SSO-origin gak pernah diwajibkan punya password, lihat §4.2 di
 * UserFactory::denganPassword()), tapi itu bukan syarat: endpoint ini
 * nerima set password baru regardless dari isi password lama --
 * baik pengisian pertama kali maupun pengisian ulang.
 *
 * Password ini yang dipakai buat login manual di halaman login
 * (AuthController::login, Hash::check()). Kalau lupa, jalur
 * pemulihannya BUKAN endpoint ini -- tetap bisa masuk lewat SSO dari
 * web lama (SsoController::verify), gak butuh reset-password broker.
 */
class PengaturanController extends Controller
{
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // Superadmin diblokir total, bukan cuma disembunyikan di FE --
        // akun superadmin memang bukan buat dipakai sehari-hari (cuma
        // "penghuni asli"), satu-satunya jalur resmi ganti password
        // superadmin lewat artisan tinker, di luar endpoint ini sama
        // sekali. Pola penolakan ikut konsistensi HakAksesController.
        if ($user->is_superadmin) {
            abort(403, 'Superadmin tidak bisa mengubah password lewat halaman ini.');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Cegah klik dobel (network lambat, tombol dipencet 2x) ngirim
        // 2 request nyaris bersamaan -- pola sama persis dengan
        // tolakJikaEntryDobel() di SetoranEntryController.
        $this->tolakJikaKlikDobel('atur-password:'.$user->id);

        $passwordSebelumnyaKosong = $user->password === null;

        DB::transaction(function () use ($user, $validated, $passwordSebelumnyaKosong) {
            // Assignment langsung + save(), bukan update() -- konsisten
            // sama pola project ini (mis. HakAksesController). Hash
            // otomatis lewat cast 'password' => 'hashed' di model User,
            // gak perlu Hash::make() manual.
            $user->password = $validated['password'];
            $user->save();

            // detail_perubahan SENGAJA tidak pernah berisi hash atau
            // plaintext password apapun (lama maupun baru) -- risiko
            // keamanan kalau ke-expose lewat log/query. Cukup flag
            // ringan buat jejak audit "pertama kali diisi" vs "diganti
            // ulang".
            ActivityLog::catat(
                actor: $user,
                jenisAksi: 'atur_password',
                objectType: 'users',
                objectId: $user->id,
                detailPerubahan: ['password_sebelumnya_kosong' => $passwordSebelumnyaKosong],
            );
        });

        return response()->json(['status' => 'ok', 'data' => null]);
    }

    /**
     * Window pendek (2 detik), cukup nangkep klik ganda tanpa ganggu
     * user yang genuinely mau ganti password lagi habis ini.
     */
    private function tolakJikaKlikDobel(string $key): void
    {
        if (! RateLimiter::attempt($key, 1, fn () => true, 2)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Tunggu sebentar sebelum menyimpan lagi.',
                'errors' => (object) [],
            ], 429));
        }
    }
}
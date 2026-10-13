<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Loket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\ActivityLog;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * 'identifier' menampung DUA kemungkinan kolom (user_id_sopp
     * ATAU username) -- lookup pakai orWhere() aman karena satu
     * baris cuma punya salah satu dari dua kolom itu yang pernah
     * keisi (§4.2), tidak akan pernah tabrakan.
     */
    private const DUMMY_HASH_UNTUK_TIMING = '$2y$12$CjpWYnYyeruSXKxhxmJgZuN0DdE36ndrssEtpjtsF91DA54n8xMvC';

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'identifier.required' => 'Username wajib diisi.',
            'identifier.string' => 'Username tidak valid.',
            'password.required' => 'Password wajib diisi.',
            'password.string' => 'Password tidak valid.',
        ]);

        $user = User::where('user_id_sopp', $validated['identifier'])
            ->orWhere('username', $validated['identifier'])
            ->first();

        if (!$user) {
            Hash::check($validated['password'], self::DUMMY_HASH_UNTUK_TIMING); // dummy check untuk menghindari timing attack
            Log::warning('Percobaan login dengan identifier tidak dikenal', [
                'identifier' => $validated['identifier'],
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah.',
                'errors' => (object) [],
            ], 401);
        }

        if ($user->password === null || !Hash::check($validated['password'], $user->password)) {
            Hash::check($validated['password'], self::DUMMY_HASH_UNTUK_TIMING); // dummy check untuk menghindari timing attack
            ActivityLog::catat(actor: $user, jenisAksi: 'login_gagal');

            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah.',
                'errors' => (object) [],
            ], 401);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        ActivityLog::catat(actor: $user, jenisAksi: 'login_manual');

        return response()->json([
            'status' => 'ok',
            'data' => [
                'user' => array_merge(
                    [
                        'nama' => $user->nama,
                        'loket_id' => $user->loket_id,
                        'nama_loket' => Loket::find($user->loket_id)?->nama_loket,
                    ],
                    $user->kapabilitasUntukApi(),
                ),
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        ActivityLog::catat(actor: $user, jenisAksi: 'logout_manual');

        return response()->json([
            'status' => 'ok',
            'data' => null,
        ]);
    }
}

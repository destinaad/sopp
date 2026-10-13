<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/me
 *
 * Sumber kapabilitas terkini buat FE (mis. setelah refresh halaman,
 * cookie session masih valid tapi FE butuh tau ulang siapa yang
 * login dan boleh apa). Pola pelaporan kapabilitas WAJIB sama persis
 * dengan SsoController::verify() (§3.4) -- makanya dua-duanya manggil
 * method terpusat yang sama, bukan ditulis ulang manual di sini.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

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
}
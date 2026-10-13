<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loket;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/lokets
 *
 * Daftar referensi semua loket -- dipakai FE buat dropdown pilih
 * cabang di Card A (bisa_input_setoran_semua_loket) dan nanti di
 * Detail Setoran (bisa_edit_setoran_semua_loket). SENGAJA TIDAK
 * digerbang kapabilitas apapun -- nama cabang bukan data sensitif,
 * cukup auth:sanctum biasa. FE yang menentukan kapan dropdown ini
 * ditampilkan/diaktifkan, berdasarkan flag kapabilitas dari GET /me.
 */
class LoketController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'data' => Loket::orderBy('nama_loket')
                ->get(['loket_id', 'nama_loket']),
        ]);
    }
}
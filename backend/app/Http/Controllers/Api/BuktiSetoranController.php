<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\UploadsBuktiSetoran;
use App\Http\Controllers\Controller;
use App\Models\SetoranDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuktiSetoranController extends Controller
{
    use UploadsBuktiSetoran;

    public function show(Request $request, string $path): StreamedResponse
    {
        $entry = SetoranDetail::with('setoranHarian')
            ->where('foto_bukti_path', $path)
            ->firstOrFail();

        $user = $request->user();
        $bolehAkses = $user->is_superadmin
            || $user->bisa_akses_laporan
            || $entry->setoranHarian->user_id === $user->id
            || ($user->bisa_edit_setoran_semua_loket && $entry->setoranHarian->status === 'submitted');

        if (! $bolehAkses) {
            abort(403, 'Tidak memiliki akses ke foto bukti ini.');
        }

        // $path itu sendiri (dari DB/URL) TIDAK punya prefix folder
        // fisik -- ditambahin lagi di sini, satu-satunya tempat baca.
        return Storage::disk('local')->response(self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path);
    }
}

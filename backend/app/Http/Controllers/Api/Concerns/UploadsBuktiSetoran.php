<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

trait UploadsBuktiSetoran
{
    private const FOLDER_FISIK_BUKTI_SETORAN = 'bukti_setoran';

    private function simpanFotoBukti(UploadedFile $file, string $tanggal, int $loketId, string $metode): string
{
    $namaFile = sprintf('%d_%s_%d_%s.%s', $loketId, $metode, now()->timestamp, Str::random(6), $file->getClientOriginalExtension());
    $file->storeAs(self::FOLDER_FISIK_BUKTI_SETORAN . "/{$tanggal}", $namaFile, 'local');
    return "{$tanggal}/{$namaFile}";
}
}
<?php

namespace App\Http\Controllers\Api\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared antara LaporanController::index() dan LaporanExportController
 * (export()+download()) -- SEMUA endpoint Laporan pakai filter yang
 * PERSIS sama, dan "download = apa yang ditampilkan" (§A v10) artinya
 * resolusi tanggal/mode HARUS identik di 3 tempat ini. Diekstrak ke
 * trait (bukan di-copy 3x) supaya gak ada risiko drift kalau salah
 * satu lupa di-update pas ada perubahan aturan.
 *
 * Param per-mode DIKONFIRMASI ke Rev2.docx Tabel 9 + cross-check
 * konvensi controller lain di project yang SAMA (bukan tebakan):
 *   - mode=harian  -> `tanggal` (singular) -- sama kayak
 *     PelunasanController/SetoranEntryController, BUKAN tanggal_mulai.
 *   - mode=bulanan -> `bulan` (YYYY-MM, mentah dari dropdown FE --
 *     DIKONFIRMASI LANGSUNG ke FE).
 *   - mode=rentang -> `tanggal_mulai`+`tanggal_akhir` -- nama
 *     `tanggal_akhir` (BUKAN `tanggal_selesai`) disamakan dengan
 *     RiwayatSetoranController/LogAktivitasController.
 * Ketiganya diterjemahkan SEKALI di sini ke representasi seragam
 * tanggal_mulai/tanggal_akhir, biar LaporanDataService dkk di bawahnya
 * gak perlu tau soal perbedaan param per-mode sama sekali.
 */
trait ValidatesFilterLaporan
{
    /**
     * DIKONFIRMASI final (7 September 2026) -- 366 hari. Taruh di sini
     * (bukan di masing-masing controller) supaya cuma ada SATU angka
     * yang perlu diganti kalau nanti ada revisi kebijakan.
     */
    private const MAKS_HARI_RENTANG_LAPORAN = 366;

    /**
     * @return array{mode:string, loket_id:?int, tanggal_mulai:string, tanggal_akhir:string, teller_id:?string}
     */
    private function validasiFilterLaporan(Request $request): array
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['harian', 'bulanan', 'rentang'])],
            'loket_id' => ['nullable', 'integer', 'exists:lokets,loket_id'],

            // mode=harian SAJA -- 1 tanggal spesifik, bukan rentang.
            'tanggal' => [
                Rule::excludeIf(fn() => $request->input('mode') !== 'harian'),
                Rule::requiredIf(fn() => $request->input('mode') === 'harian'),
                'date_format:Y-m-d',
            ],

            // mode=bulanan SAJA -- nilai mentah dropdown FE, format
            // YYYY-MM. Diterjemahkan ke awal/akhir bulan di bawah --
            // WAJIB lewat createFromFormat('Y-m-d', ...-01), BUKAN
            // createFromFormat('Y-m', ...) -- yang terakhir itu
            // rollover bug (hari-ini ikut ke-fill kalau > jumlah hari
            // bulan target, bisa geser ke bulan lain sama sekali).
            'bulan' => [
                Rule::excludeIf(fn() => $request->input('mode') !== 'bulanan'),
                Rule::requiredIf(fn() => $request->input('mode') === 'bulanan'),
                'date_format:Y-m',
            ],

            // mode=rentang SAJA.
            'tanggal_mulai' => [
                Rule::excludeIf(fn() => in_array($request->input('mode'), ['harian', 'bulanan'], true)),
                Rule::requiredIf(fn() => $request->input('mode') === 'rentang'),
                'date_format:Y-m-d',
            ],
            'tanggal_akhir' => [
                'nullable',
                Rule::excludeIf(fn() => in_array($request->input('mode'), ['harian', 'bulanan'], true)),
                'date_format:Y-m-d',
                'after_or_equal:tanggal_mulai',
                Rule::requiredIf(fn() => $request->input('mode') === 'rentang'),
            ],

            // String, bukan integer -- ini id legacy sistem lama
            // (user_id_sopp), bukan primary key lokal. Cuma masuk akal
            // kalau loket_id juga diisi (Bentuk A tidak punya konsep
            // filter per-teller sama sekali).
            'teller_id' => ['nullable', 'string', Rule::prohibitedIf(! $request->filled('loket_id'))],
        ]);

        $tanggalMulai = match ($validated['mode']) {
            'harian' => $validated['tanggal'],
            'bulanan' => Carbon::createFromFormat('Y-m-d', $validated['bulan'] . '-01')->startOfDay()->toDateString(),
            'rentang' => $validated['tanggal_mulai'],
        };

        $tanggalAkhir = match ($validated['mode']) {
            'harian' => $tanggalMulai,
            'bulanan' => Carbon::parse($tanggalMulai)->endOfMonth()->toDateString(),
            'rentang' => $validated['tanggal_akhir'],
        };

        if (Carbon::parse($tanggalMulai)->diffInDays(Carbon::parse($tanggalAkhir)) > self::MAKS_HARI_RENTANG_LAPORAN) {
            throw ValidationException::withMessages([
                'tanggal_akhir' => 'Rentang tanggal maksimal ' . self::MAKS_HARI_RENTANG_LAPORAN . ' hari.',
            ]);
        }

        return [
            'mode' => $validated['mode'],
            'loket_id' => isset($validated['loket_id'])
                ? (int) $validated['loket_id']
                : null,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_akhir' => $tanggalAkhir,
            'teller_id' => $validated['teller_id'] ?? null,
        ];
    }
}
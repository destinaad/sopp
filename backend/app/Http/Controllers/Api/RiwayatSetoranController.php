<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\UploadsBuktiSetoran;
use App\Models\Loket;
use App\Models\SetoranHarian;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\SelisihPerUserService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KEPUTUSAN FINAL (nyusul diskusi status_keuangan personal): selisih,
 * status_keuangan, DAN total_pendapatan di halaman ini SEKARANG semua
 * PERSONAL (SelisihPerUserService, live-fetch bridge) -- bukan lagi
 * loket-wide (SetoranHarian::selisih()/isLunas()/totalPendapatanSopp(),
 * baca snapshot_sopp yang di-cache). Tiga-tiganya ditarik dari 1 sumber
 * fetch yang sama per baris (lihat SelisihPerUserService::ringkasUntukRow()),
 * jadi gagal/suksesnya bareng -- gak ada lagi kombinasi "status_keuangan
 * kehitung tapi total_pendapatan kosong" atau sebaliknya.
 *
 * Konsekuensinya: filter status_keuangan dan kolom-kolom personal di
 * export() sekarang WAJIB bridge fetch, bukan lagi baca kolom cache.
 * Guard rentang 30 hari (index() dan export(), DUA-DUANYA, mode
 * personal maupun lintas-loket -- gak dicabang lagi kayak sebelumnya)
 * ada buat jaga rentang yang di-fetch tetap wajar, lihat komentar di
 * masing-masing method.
 */
class RiwayatSetoranController extends Controller
{
    use UploadsBuktiSetoran;

    private const PER_PAGE = 8;
    private const CAP_HARI_TANPA_TANGGAL_MULAI = 30;

    public function __construct(private readonly SelisihPerUserService $selisihPerUserService) {}

    /**
     * Anchor cap 30 hari WAJIB dari tanggal_akhir kalau FE ngirim itu
     * (bukan selalu now()) -- tanggal_akhir sah dikirim sendirian
     * tanpa tanggal_mulai (validasinya cuma mewajibkan
     * tanggal_akhir >= tanggal_mulai KALAU dua-duanya ada). Kalau
     * cap-nya selalu dihitung dari now() sementara tanggal_akhir yang
     * dikirim jauh di masa lalu, dua syarat WHERE jadi saling gak
     * mungkin ketemu (>= [now-30hari] DAN <= [tanggal_akhir lama]) --
     * query-nya selalu kosong, bukan cuma kurang presisi.
     */
    private function tanggalCapTanpaMulai(array $validated): string
    {
        $anchor = isset($validated['tanggal_akhir'])
            ? Carbon::parse($validated['tanggal_akhir'])
            : now();

        return $anchor->subDays(self::CAP_HARI_TANPA_TANGGAL_MULAI)->toDateString();
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'status_workflow' => ['nullable', Rule::in(['draft', 'submitted'])],
            'status_keuangan' => ['nullable', Rule::in(['lunas', 'belum_lunas', 'gagal_dimuat'])],
            'loket_id' => ['nullable', 'integer', 'exists:lokets,loket_id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $page = $validated['page'] ?? 1;
        $modeLintasLoket = isset($validated['loket_id']);

        if ($modeLintasLoket) {
            Gate::authorize('updateForOtherLoket', SetoranHarian::class);

            ActivityLog::catat($user, 'riwayat_lintas_loket_dilihat', null, null, [
                'loket_id' => $validated['loket_id'],
                'tanggal_mulai' => $validated['tanggal_mulai'] ?? null,
                'tanggal_akhir' => $validated['tanggal_akhir'] ?? null,
            ]);

            $query = SetoranHarian::where('loket_id', $validated['loket_id'])
                ->where('status', 'submitted')
                ->with(['entries', 'user'])
                ->orderByDesc('tanggal_transaksi');
        } else {
            $query = SetoranHarian::where('user_id', $user->id)
                ->with(['entries', 'loket', 'user'])
                ->orderByDesc('tanggal_transaksi');
        }

        if (isset($validated['tanggal_mulai'])) {
            $query->where('tanggal_transaksi', '>=', $validated['tanggal_mulai']);
        }
        if (isset($validated['tanggal_akhir'])) {
            $query->where('tanggal_transaksi', '<=', $validated['tanggal_akhir']);
        }
        if (isset($validated['status_workflow'])) {
            $query->where('status', $validated['status_workflow']);
        }

        // Guard performa (mode-agnostic, sengaja SAMA buat personal
        // maupun lintas-loket -- lihat komentar class-level): filter
        // status_keuangan (di bawah) sekarang PERSONAL, butuh bridge
        // fetch buat SELURUH candidate set sebelum bisa difilter+
        // dipaginate (gak bisa dihitung di level SQL, dan gak bisa
        // ditunda ke $items doang karena filternya sendiri butuh
        // datanya). Kalau tanggal_mulai gak dikirim FE, batasi ke 30
        // hari terakhir biar rentang yang di-fetch ke bridge tetap
        // wajar -- lihat SelisihPerUserService::untukBanyakRow().
        if (isset($validated['status_keuangan']) && !isset($validated['tanggal_mulai'])) {
            $query->where('tanggal_transaksi', '>=', $this->tanggalCapTanpaMulai($validated));
        }

        if (isset($validated['status_keuangan'])) {
            // 1x fetch bridge buat SEMUA row candidate (bukan cuma 1
            // halaman) -- wajib, karena status_keuangan personal gak
            // bisa dihitung di SQL, filter harus jalan sebelum
            // dipaginate. Guard di atas yang jaga rentang ini bounded.
            $semua = $query->get();
            $selisihSemua = $this->selisihPerUserService->untukBanyakRow($semua);

            $semua = $semua->filter(
                fn(SetoranHarian $row) => $this->statusKeuangan($selisihSemua[$row->id]['selisih_sendiri']) === $validated['status_keuangan']
            )->values();
            $totalItems = $semua->count();
            $items = $semua->forPage($page, self::PER_PAGE)->values();

            // Data personal buat $items udah ke-cover di $selisihSemua
            // di atas -- gak perlu fetch bridge kedua kalinya buat
            // halaman yang sama.
            $selisihPerRow = $items->mapWithKeys(
                fn(SetoranHarian $row) => [$row->id => $selisihSemua[$row->id]]
            )->all();
        } else {
            $totalItems = (clone $query)->count();
            $items = $query->forPage($page, self::PER_PAGE)->get();
            // Batch 1x per grup loket (bukan 1x per row) -- $items di
            // sini udah PASTI dibatasi ke 1 halaman (maks PER_PAGE
            // baris), jadi rentang tanggal yang di-fetch juga otomatis
            // wajar. JANGAN panggil ini pakai koleksi yang lebih luas
            // dari 1 halaman -- lihat peringatan di SelisihPerUserService
            // soal rentang unbounded.
            $selisihPerRow = $this->selisihPerUserService->untukBanyakRow($items);
        }

        return response()->json([
            'status' => 'ok',
            'data' => [
                'items' => $items->map(
                    fn(SetoranHarian $row) => $this->mapItem($row, $modeLintasLoket, $user, $selisihPerRow[$row->id])
                )->all(),
                'meta' => [
                    'current_page' => $page,
                    'per_page' => self::PER_PAGE,
                    'total_items' => $totalItems,
                    'total_pages' => max((int) ceil($totalItems / self::PER_PAGE), 1),
                ],
                'ringkasan' => $this->ringkasan($user->id),
            ],
        ]);
    }

    /**
     * KEPUTUSAN FINAL: personal. $selisihSendiri null berarti fetch
     * bridge buat teller ini gagal (koneksi putus / gak punya
     * identitas legacy) -- BUKAN lagi dibaca dari snapshot_sopp.
     */
    private function statusKeuangan(?int $selisihSendiri): string
    {
        if ($selisihSendiri === null) {
            return 'gagal_dimuat';
        }
        return $selisihSendiri === 0 ? 'lunas' : 'belum_lunas';
    }

    /**
     * $selisihPersonal: hasil SelisihPerUserService untuk row INI doang
     * (bukan dihitung ulang di sini) -- dipanggil batch dari index(),
     * lihat komentar di sana soal kenapa harus di-batch bukan per-row.
     *
     * @param array{selisih_sendiri: ?int, pendapatan: ?list<array<string,mixed>>, total_pendapatan: ?int} $selisihPersonal
     */
    private function mapItem(SetoranHarian $row, bool $sertakanNamaTeller, User $currentUser, array $selisihPersonal): array
    {
        $entries = $row->entries;
        $bolehHapusSubmittedBerisi = (bool) $currentUser->bisa_edit_setoran_semua_loket;

        $item = [
            'id' => $row->id,
            'tanggal_transaksi' => $row->tanggal_transaksi->toDateString(),
            // KEPUTUSAN FINAL: personal (dari SelisihPerUserService),
            // bukan lagi $row->totalPendapatanSopp() loket-wide.
            'total_pendapatan' => $selisihPersonal['total_pendapatan'],
            'nominal_transfer' => (int) $entries->where('metode_pembayaran', 'transfer')->sum('nominal'),
            'nominal_qris' => (int) $entries->where('metode_pembayaran', 'qris')->sum('nominal'),
            'nominal_tunai' => (int) $entries->where('metode_pembayaran', 'tunai')->sum('nominal'),
            'nominal_kpay' => (int) $entries->where('metode_pembayaran', 'kpay')->sum('nominal'),
            'selisih' => $selisihPersonal['selisih_sendiri'],
            'status_keuangan' => $this->statusKeuangan($selisihPersonal['selisih_sendiri']),
            'status_workflow' => $row->status,
            'bisa_dihapus' => $row->status === 'draft' || $bolehHapusSubmittedBerisi,
            'dibuat_via_bantuan_pelunasan' => $row->dibuat_via_bantuan_pelunasan,
        ];

        if (! $sertakanNamaTeller) {
            $item['nama_loket'] = $row->loket?->nama_loket ?? '-';
        }

        if ($sertakanNamaTeller) {
            $item['nama_teller'] = $row->user->nama;
        }

        return $item;
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $setoranHarian = SetoranHarian::findOrFail($id);
        $user = $request->user();
        $pemilik = $setoranHarian->user_id === $user->id;
        $submitted = $setoranHarian->status === 'submitted';

        if ($submitted) {
            if (blank($validated['alasan'] ?? null)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Alasan wajib diisi untuk menghapus setoran yang sudah submitted dan berisi entry.',
                    'errors' => ['alasan' => ['Alasan wajib diisi.']],
                ], 422);
            }

            Gate::authorize('updateForOtherLoket', SetoranHarian::class);
        } elseif (! $pemilik) {
            abort(404);
        }

        DB::transaction(function () use ($id, $user, &$setoranHarian, $validated, $submitted) {
            $setoranHarian = SetoranHarian::where('id', $id)->lockForUpdate()->firstOrFail();
            $entriesLama = $setoranHarian->entries()->get();

            $entriesLama->each(function ($entry) {
                if ($entry->foto_bukti_path) {
                    $pathUntukDihapus = self::FOLDER_FISIK_BUKTI_SETORAN . '/' . $entry->foto_bukti_path;
                    DB::afterCommit(function () use ($pathUntukDihapus) {
                        Storage::disk('local')->delete($pathUntukDihapus);
                    });
                }
                $entry->delete();
            });

            $setoranHarian->delete();

            // FIX: target_type harus literal string pendek ('setoran_harian'),
            // BUKAN SetoranHarian::class -- konsisten dengan seluruh
            // pemanggilan ActivityLog::catat() lain di codebase. Sebelumnya
            // ini tersimpan sebagai "App\Models\SetoranHarian", yang bikin
            // baris log ini tidak ke-match resolver target_type di manapun
            // (termasuk LogAktivitasController).
            ActivityLog::catat($user, 'setoran_dihapus', 'setoran_harian', $setoranHarian->id, [
                'tanggal_transaksi' => $setoranHarian->tanggal_transaksi->toDateString(),
                'loket_id' => $setoranHarian->loket_id,
                'status_saat_dihapus' => $setoranHarian->status,
                'jumlah_entry_dihapus' => $entriesLama->count(),
                'alasan' => $submitted ? $validated['alasan'] : null,
            ]);
        });

        return response()->json(['status' => 'ok', 'data' => null]);
    }

    /**
     * KEPUTUSAN FINAL: scope unduhan ikut filter aktif (tanggal_mulai,
     * tanggal_akhir, status_workflow, status_keuangan) -- BUKAN ikut
     * page. Riwayat satu-satunya tempat teller bisa unduh, jadi kalau
     * mau subset kecil udah ada jalan lewat filter tanggal; gak butuh
     * mekanisme terpisah berbasis halaman aktif. Makanya 'page' gak
     * pernah ada di validasi request method ini.
     *
     * KEPUTUSAN FINAL: kolom "Selisih (Rp)" dan "Total Pendapatan (Rp)"
     * sekarang personal juga (nyusul index()), sama kayak filter
     * status_keuangan-nya -- biar filter yang sama gak ngasih hasil
     * beda antara yang di layar vs yang di file.
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'status_workflow' => ['nullable', Rule::in(['draft', 'submitted'])],
            'status_keuangan' => ['nullable', Rule::in(['lunas', 'belum_lunas', 'gagal_dimuat'])],
            'loket_id' => ['nullable', 'integer', 'exists:lokets,loket_id'],
        ]);

        $user = $request->user();
        $modeLintasLoket = isset($validated['loket_id']);
        $namaLoket = null;

        if ($modeLintasLoket) {
            Gate::authorize('updateForOtherLoket', SetoranHarian::class);

            ActivityLog::catat($user, 'riwayat_lintas_loket_diekspor', null, null, [
                'loket_id' => $validated['loket_id'],
                'tanggal_mulai' => $validated['tanggal_mulai'] ?? null,
                'tanggal_akhir' => $validated['tanggal_akhir'] ?? null,
            ]);

            $query = SetoranHarian::where('loket_id', $validated['loket_id'])
                ->where('status', 'submitted')
                ->with(['entries', 'user'])
                ->orderBy('tanggal_transaksi');
            $namaLoket = Loket::find($validated['loket_id'])?->nama_loket ?? '-';
        } else {
            $query = SetoranHarian::where('user_id', $user->id)
                ->with(['entries', 'user'])
                ->orderBy('tanggal_transaksi');
        }

        // Guard performa (mode-agnostic, 30 hari -- berlaku ke KEDUA
        // mode, beda dari sebelumnya yang cuma mode lintas-loket):
        // export() selalu ambil SEMUA row tanpa pagination, dan
        // sekarang tiap baris butuh fetch bridge personal buat kolom
        // Selisih & Total Pendapatan -- bukan cuma pas status_keuangan
        // difilter. Kalau tanggal_mulai gak dikirim, batasi ke 30 hari
        // terakhir biar rentang yang di-fetch tetap wajar.
        if (!isset($validated['tanggal_mulai'])) {
            $query->where('tanggal_transaksi', '>=', $this->tanggalCapTanpaMulai($validated));
        }

        if (isset($validated['tanggal_mulai'])) {
            $query->where('tanggal_transaksi', '>=', $validated['tanggal_mulai']);
        }
        if (isset($validated['tanggal_akhir'])) {
            $query->where('tanggal_transaksi', '<=', $validated['tanggal_akhir']);
        }
        if (isset($validated['status_workflow'])) {
            $query->where('status', $validated['status_workflow']);
        }

        $rows = $query->get();

        // 1x fetch bridge buat SEMUA row yang lolos filter tanggal/
        // workflow di atas (guard 30 hari yang jaga rentang ini tetap
        // wajar) -- dipakai bareng buat kolom Selisih, Total
        // Pendapatan, DAN filter status_keuangan di bawah.
        $selisihPerRow = $this->selisihPerUserService->untukBanyakRow($rows);

        if (isset($validated['status_keuangan'])) {
            $rows = $rows->filter(
                fn(SetoranHarian $row) => $this->statusKeuangan($selisihPerRow[$row->id]['selisih_sendiri']) === $validated['status_keuangan']
            )->values();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Riwayat Setoran');

        $baris = 1;
        $sheet->setCellValue("A{$baris}", 'FILTER PERIODE:');
        $sheet->setCellValue("B{$baris}", isset($validated['tanggal_mulai']) || isset($validated['tanggal_akhir'])
            ? Carbon::parse($validated['tanggal_mulai'] ?? '1970-01-01')->translatedFormat('d M Y')
            . ' s/d ' . Carbon::parse($validated['tanggal_akhir'] ?? now())->translatedFormat('d M Y')
            : 'Semua tanggal');
        $baris++;

        if ($modeLintasLoket) {
            $sheet->setCellValue("A{$baris}", 'FILTER LOKET:');
            $sheet->setCellValue("B{$baris}", $namaLoket);
            $baris++;
        }
        $baris++;

        $headerRow = $baris;
        $sheet->setCellValue("A{$headerRow}", 'Tanggal');
        $sheet->setCellValue("B{$headerRow}", 'Total Pendapatan (Rp)');
        $sheet->mergeCells("C{$headerRow}:F{$headerRow}");
        $sheet->setCellValue("C{$headerRow}", 'Metode Pembayaran');
        $sheet->setCellValue("G{$headerRow}", 'Selisih (Rp)');
        $sheet->setCellValue("H{$headerRow}", 'Status');
        $baris++;

        $sheet->setCellValue("C{$baris}", 'Transfer');
        $sheet->setCellValue("D{$baris}", 'QRIS');
        $sheet->setCellValue("E{$baris}", 'Tunai');
        $sheet->setCellValue("F{$baris}", 'KPay');
        $baris++;

        $dilewati = 0;
        $totalPendapatan = $totalTransfer = $totalQris = $totalTunai = $totalKpay = $totalSelisih = 0;

        foreach ($rows as $row) {
            $personal = $selisihPerRow[$row->id];

            // Kolom C-F (breakdown metode pembayaran) dan H (status
            // workflow) itu dari entries row ini sendiri -- gak butuh
            // bridge sama sekali, jadi SELALU valid, gak peduli fetch
            // personal-nya gagal atau enggak. Ditulis dan diikutkan ke
            // total apa adanya buat semua baris.
            $entries = $row->entries;
            $transfer = (int) $entries->where('metode_pembayaran', 'transfer')->sum('nominal');
            $qris = (int) $entries->where('metode_pembayaran', 'qris')->sum('nominal');
            $tunai = (int) $entries->where('metode_pembayaran', 'tunai')->sum('nominal');
            $kpay = (int) $entries->where('metode_pembayaran', 'kpay')->sum('nominal');

            $sheet->setCellValue("A{$baris}", $row->tanggal_transaksi->format('d M Y'));
            $sheet->setCellValue("C{$baris}", $transfer);
            $sheet->setCellValue("D{$baris}", $qris);
            $sheet->setCellValue("E{$baris}", $tunai);
            $sheet->setCellValue("F{$baris}", $kpay);
            $sheet->setCellValue("H{$baris}", $row->status === 'draft' ? 'Draft' : 'Submitted');

            $totalTransfer += $transfer;
            $totalQris += $qris;
            $totalTunai += $tunai;
            $totalKpay += $kpay;

            // KEPUTUSAN FINAL: baris TETAP ditulis walau fetch bridge
            // personal gagal (bukan lagi di-skip) -- teller berhak tau
            // baris itu ada, cuma dua kolom yang butuh bridge ini yang
            // gak tersedia. Kolom B & G ditandai teks, gak ikut TOTAL
            // SEMUA (gak ada angka valid buat dijumlah).
            if ($personal['selisih_sendiri'] === null) {
                $dilewati++;
                $sheet->setCellValue("B{$baris}", 'Gagal dimuat');
                $sheet->setCellValue("G{$baris}", 'Gagal dimuat');
                $baris++;
                continue;
            }

            $pendapatan = $personal['total_pendapatan'];
            $selisih = $personal['selisih_sendiri'];

            $sheet->setCellValue("B{$baris}", $pendapatan);
            $sheet->setCellValue("G{$baris}", $selisih);

            $totalPendapatan += $pendapatan;
            $totalSelisih += $selisih;
            $baris++;
        }

        $sheet->setCellValue("A{$baris}", 'TOTAL SEMUA');
        $sheet->setCellValue("B{$baris}", $totalPendapatan);
        $sheet->setCellValue("C{$baris}", $totalTransfer);
        $sheet->setCellValue("D{$baris}", $totalQris);
        $sheet->setCellValue("E{$baris}", $totalTunai);
        $sheet->setCellValue("F{$baris}", $totalKpay);
        $sheet->setCellValue("G{$baris}", $totalSelisih);
        $baris++;

        if ($dilewati > 0) {
            $baris++;
            $sheet->setCellValue("A{$baris}", "Catatan: {$dilewati} baris di atas gagal terhubung ke sistem SOPP. Kolom Total Pendapatan dan Selisih untuk baris tersebut ditandai 'Gagal dimuat', tidak diikutkan di Total Semua.");
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'riwayat-setoran-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * KEPUTUSAN FINAL: total_setoran_lunas, pending_selisih, DAN
     * ada_data_belum_lengkap semua personal sekarang.
     *
     * Sengaja 1x fetch bridge buat row-set "submitted, bulan berjalan"
     * (bukan 3x terpisah) -- ketiga metrik itu butuh row-set yang
     * PERSIS sama (submitted + bulan berjalan), jadi dipakai bareng
     * daripada manggil bridge 3x buat rentang yang tumpang tindih.
     * Bulan lalu (buat perbandingan persentase) tetap fetch terpisah
     * lewat totalSetoranLunas() karena rentangnya beda.
     */
    private function ringkasan(int $userId): array
    {
        $hariIni = Carbon::today();
        $hariBerjalan = $hariIni->day;
        $awalBulanIni = $hariIni->copy()->startOfMonth()->toDateString();

        $rowsBulanIni = SetoranHarian::where('user_id', $userId)
            ->where('status', 'submitted')
            ->where('tanggal_transaksi', '>=', $awalBulanIni)
            ->with(['entries', 'user'])
            ->get();
        $selisihBulanIni = $this->selisihPerUserService->untukBanyakRow($rowsBulanIni);

        $totalSetoranLunasBulanIni = $rowsBulanIni
            ->filter(fn(SetoranHarian $r) => $selisihBulanIni[$r->id]['selisih_sendiri'] === 0)
            ->sum(fn(SetoranHarian $r) => $r->totalSetor());

        $pendingSelisih = $rowsBulanIni->sum(function (SetoranHarian $r) use ($selisihBulanIni) {
            $selisih = $selisihBulanIni[$r->id]['selisih_sendiri'];
            return $selisih !== null && $selisih !== 0 ? abs($selisih) : 0;
        });

        $adaDataBelumLengkap = collect($selisihBulanIni)->contains(fn(array $s) => $s['selisih_sendiri'] === null);

        $awalBulanLalu = $hariIni->copy()->subMonthNoOverflow()->startOfMonth();
        $akhirBandingBulanLalu = $awalBulanLalu->copy()->addDays($hariBerjalan - 1)
            ->min($awalBulanLalu->copy()->endOfMonth());
        $totalSetoranLunasBulanLalu = $this->totalSetoranLunas(
            $userId,
            $awalBulanLalu->toDateString(),
            $akhirBandingBulanLalu->toDateString(),
        );

        $persentaseVsBulanLalu = null;
        if ($totalSetoranLunasBulanLalu > 0) {
            $persentaseVsBulanLalu = round(
                (($totalSetoranLunasBulanIni - $totalSetoranLunasBulanLalu) / $totalSetoranLunasBulanLalu) * 100,
                1
            );
        }

        $totalEntryBulanIni = SetoranHarian::where('user_id', $userId)
            ->where('tanggal_transaksi', '>=', $awalBulanIni)
            ->where('status', 'submitted')
            ->count();

        $rataRataEntryPerHari = round($totalEntryBulanIni / $hariBerjalan, 1);

        return [
            'total_setoran_lunas' => $totalSetoranLunasBulanIni,
            'persentase_vs_bulan_lalu' => $persentaseVsBulanLalu,
            'total_entry_bulan_ini' => $totalEntryBulanIni,
            'rata_rata_entry_per_hari' => $rataRataEntryPerHari,
            'pending_selisih' => $pendingSelisih,
            'ada_data_belum_lengkap' => $adaDataBelumLengkap,
        ];
    }

    /**
     * Dipakai buat rentang SELAIN bulan berjalan (bulan lalu, buat
     * perbandingan persentase di ringkasan()) -- makanya masih fetch
     * bridge sendiri, gak bisa gabung sama $rowsBulanIni di atas.
     */
    private function totalSetoranLunas(int $userId, string $awal, string $akhir): int
    {
        $rows = SetoranHarian::where('user_id', $userId)
            ->where('status', 'submitted')
            ->whereBetween('tanggal_transaksi', [$awal, $akhir])
            ->with(['entries', 'user'])
            ->get();

        $selisihPerRow = $this->selisihPerUserService->untukBanyakRow($rows);

        return $rows
            ->filter(fn(SetoranHarian $r) => $selisihPerRow[$r->id]['selisih_sendiri'] === 0)
            ->sum(fn(SetoranHarian $r) => $r->totalSetor());
    }
}
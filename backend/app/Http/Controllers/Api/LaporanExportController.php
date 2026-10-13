<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ThrottlesBridgeRefresh;
use App\Http\Controllers\Api\Concerns\ValidatesFilterLaporan;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Loket;
use App\Services\LaporanDataService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/admin/laporan/export, GET /api/v1/admin/laporan/download
 * -- §A v10. Dua endpoint ini SENGAJA berbagi 99% logic (bikin sheet)
 * lewat `unduh()` -- satu-satunya beda adalah kolom "Loket" (cuma
 * relevan buat Bentuk A, dibuang di /download) dan nama file.
 * "download = apa yang ditampilkan" (§A) -- filter yang dipakai PERSIS
 * sama seperti layar (mode+loket_id+tanggal_*+teller_id), lewat trait
 * yang sama dengan LaporanController::index().
 *
 * SPLIT SHEET PER BULAN (keputusan EKSEKUSI, bukan optional -- pola
 * laporan yang sudah dipakai user sebelumnya (mis. laporan Bu Wiwik)
 * memang per-bulan): kalau rentang yang diminta nyebrang >1 bulan
 * kalender, file HARUS berisi 1 sheet per bulan (nama sheet = nama
 * bulan+tahun), bukan 1 sheet gepeng isi semua tanggal.
 *
 * KENAPA re-panggil generate() PER BULAN (bukan cuma motong array
 * `items` hasil 1x generate()): Bentuk A (loket_id kosong) itu 1
 * baris/LOKET yang di-AGGREGATE atas SELURUH periode yang diminta --
 * gak ada baris per-tanggal buat dipotong. Vendor apa aja yang muncul,
 * loket mana aja yang relevan, itu semua bisa BEDA per bulan. Jadi
 * satu-satunya cara yang benar adalah manggil generate() ulang dengan
 * rentang dipersempit ke 1 bulan kalender per panggilan -- bukan cuma
 * post-process hasil 1 panggilan gede. Ini juga otomatis benar buat
 * Bentuk B (yang sebenarnya BISA cuma di-split dari 1x panggilan, tapi
 * disatukan ke pola yang sama biar cuma ada 1 alur, bukan 2 strategi
 * beda utk 2 Bentuk). Konsekuensi performa: pemanggilan generate()
 * berulang ini TETAP murah, karena data bridge yang mendasarinya sudah
 * di-cache PER HARI (lihat LaporanDataService §cache) -- manggil ulang
 * cuma re-agregasi dari cache, bukan re-fetch ke bridge.
 *
 * PENTING beda dari index(): $paginasi=false tiap panggilan generate()
 * -- tiap sheet harus berisi SELURUH bulan itu, bukan 1 halaman 8-item
 * kayak tampilan layar.
 *
 * BARIS KOSONG (hari tanpa transaksi bridge & tanpa setoran) sudah
 * dibuang di LaporanDataService::bentukB(), jadi export otomatis ikut
 * bersih -- tidak ada filter tambahan di controller ini.
 *
 * OPEN ITEM (belum ada jawaban eksplisit di dokumen kontrak): segmen
 * `[loket]` di nama file `laporan_[loket]_[periode].xlsx` diisi
 * `semua-loket` kalau `loket_id` null -- ini SEKARANG cuma relevan buat
 * `/export` Bentuk A (semua loket, keputusan sah), karena `/download`
 * sudah mewajibkan `loket_id` (lihat `download()`) jadi gak akan pernah
 * kena cabang ini.
 */
class LaporanExportController extends Controller
{
    use ThrottlesBridgeRefresh;
    use ValidatesFilterLaporan;

    private const KOLOM_METODE_SETORAN = ['transfer' => 'Transfer', 'qris' => 'QRIS', 'tunai' => 'Tunai', 'kpay' => 'KPay'];

    /**
     * Batas rentang KHUSUS export/download -- LEBIH KETAT dari
     * MAKS_HARI_RENTANG_LAPORAN (366, di ValidatesFilterLaporan) yang
     * dipakai index(). Alasan beda: index() paginated & bisa gagal-
     * sebagian per-halaman tanpa nge-block user; export/download itu 1
     * request SINKRON yang WAJIB selesai utuh sebelum file bisa
     * diunduh -- kalau kelamaan, gak ada "coba lagi nanti", langsung
     * keliatan gagal/hang ke user.
     *
     * Angka 90 hari diturunkan dari benchmark manual ke bridge.php
     * (tes 7 September 2026): worst-case ~2 detik/chunk 31-hari,
     * dibulatkan ke 3 detik/chunk buat margin data produksi yang lebih
     * ramai + overhead bikin file Excel (belum terukur langsung, cuma
     * tebakan). Target budget ~10 detik dari asumsi timeout infra
     * paling ketat ~30 detik (BELUM ada angka pasti dari hosting).
     * (10 detik / 3 detik per chunk) x 31 hari = 93, dibulatkan ke 90.
     *
     * REVISI angka ini begitu infra produksi udah jelas: ukur ulang (1)
     * timeout asli dari hosting, (2) waktu /export sungguhan (bukan
     * bridge.php doang) buat 1 chunk 31 hari -- itu ngasih angka fetch+
     * build-Excel sekaligus, bukan estimasi.
     */
    private const MAKS_HARI_EXPORT_DOWNLOAD = 90;

    public function __construct(
        private readonly LaporanDataService $laporanDataService,
    ) {
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->unduh($request, sertakanKolomLoket: true, wajibLoketId: false, aksiLog: 'laporan_diekspor');
    }

    public function download(Request $request): StreamedResponse
    {
        // §A: "/download kolom & nama file: sama seperti /export minus
        // kolom Loket". Auto-carry teller_id -- tidak butuh logic
        // tambahan di sini, karena teller_id SUDAH bagian dari filter
        // yang sama (ValidatesFilterLaporan) yang dipakai index() untuk
        // rendering layar -- apa pun yang aktif di layar otomatis ikut
        // ke sini selama FE mengirim query string yang sama persis.
        //
        // wajibLoketId: true -- beda dari export(), kontrak FE bilang
        // /download itu "untuk 1 loket spesifik", jadi Bentuk A (semua
        // loket) TIDAK valid buat endpoint ini. Dicek di unduh() (bukan
        // di trait ValidatesFilterLaporan) karena export() tetap harus
        // boleh loket_id kosong -- naruh required di trait bakal ikut
        // ngeblokir export() juga, padahal itu gak seharusnya.
        return $this->unduh($request, sertakanKolomLoket: false, wajibLoketId: true, aksiLog: 'laporan_diunduh');
    }

    private function unduh(Request $request, bool $sertakanKolomLoket, bool $wajibLoketId, string $aksiLog): StreamedResponse
    {
        $this->authorizeAksesHalaman($request);
        $filter = $this->validasiFilterLaporan($request);

        if ($wajibLoketId && $filter['loket_id'] === null) {
            throw ValidationException::withMessages([
                'loket_id' => 'Wajib memilih loket untuk mengunduh laporan.',
            ]);
        }

        // Dicek di unduh() (BUKAN di export()/download() masing-masing)
        // -- persis pola yang sama dengan forceRefresh+throttle di bawah:
        // ini titik yang dipakai bareng, kalau nempel di 2 tempat
        // terpisah gampang drift (ubah 1 lupa yang lain).
        if (Carbon::parse($filter['tanggal_mulai'])->diffInDays(Carbon::parse($filter['tanggal_akhir'])) > self::MAKS_HARI_EXPORT_DOWNLOAD) {
            throw ValidationException::withMessages([
                'tanggal_akhir' => 'Rentang unduhan maksimal ' . self::MAKS_HARI_EXPORT_DOWNLOAD . ' hari sekali unduh. Pecah jadi beberapa kali unduh untuk periode lebih panjang.',
            ]);
        }

        // Refresh manual -- pola SAMA PERSIS kayak LaporanController::index(),
        // termasuk throttle-nya. WAJIB dipasangkan (bukan cuma forceRefresh
        // polos): tanpa throttle ini, /export dan /download jadi jalur TANPA
        // rate-limit sama sekali buat maksa bridge refetch berulang-ulang,
        // padahal itu justru alasan ThrottlesBridgeRefresh ada di index().
        // Key SENGAJA sama persis format-nya dengan index() (termasuk mode)
        // -- refresh dari index() dan refresh dari export() buat kombinasi
        // filter yang SAMA itu memang harus berbagi 1 budget, karena
        // dua-duanya memicu bridge refetch yang identik.
        //
        // CATATAN: karena export bisa memecah 1 request jadi banyak sheet
        // per bulan (lihat pecahJadiRentangBulan()), 1 klik refresh di sini
        // bisa memicu bridge refetch SEBANYAK jumlah bulan dalam rentang
        // (bisa sampai ~12x untuk rentang setahun) dalam SATU request --
        // beda dari index() yang cuma 1x fetch untuk filter yang lagi
        // ditampilkan. Throttle di bawah cuma cegah spam KLIK-nya, bukan
        // mengecilkan beban 1 klik itu sendiri.
        $forceRefresh = $request->boolean('refresh');
        if ($forceRefresh) {
            $this->tolakJikaKenaRateLimit(
                'refresh-laporan:'.$request->user()->id.':'.$filter['mode'].':'.($filter['loket_id'] ?? 'semua').':'.$filter['tanggal_mulai'].':'.$filter['tanggal_akhir']
            );
        }

        $spreadsheet = new Spreadsheet();
        $adaBridgeGagal = false;

        $rentangBulan = $this->pecahJadiRentangBulan($filter['tanggal_mulai'], $filter['tanggal_akhir']);

        foreach ($rentangBulan as $i => [$mulaiBulan, $selesaiBulan]) {
            // REVISI (bug ketemu pas review): $mode per-chunk HARUS
            // $filter['mode'] ASLI verbatim, BUKAN di-re-derive dari
            // lebar chunk. Draf sebelumnya sengaja nulis
            // `$mulaiBulan===$selesaiBulan ? 'harian' : 'rentang'` dengan
            // alasan "biar S3 asli tetap ke-detect" -- itu REPRODUKSI
            // bug yang sama persis kayak yang udah dibenerin di
            // bentukA() (deteksi S3 dari lebar tanggal, bukan dari
            // $mode). Kasus nyata yang kena: rentang 31 Jan - 5 Mar,
            // chunk Januari cuma kesisa 1 hari (31 Jan doang) --
            // ke-re-derive jadi 'harian', bikin loket_id-terisi ROUTE ke
            // Bentuk C padahal 2 sheet lain (Feb, Mar) di file yang SAMA
            // Bentuk B -- 1 sheet formatnya nyeleneh dibanding yang lain.
            // Verbatim $filter['mode'] justru SUDAH BENAR untuk semua
            // kasus: mode=harian dari awal cuma pernah ngasilin 1 chunk
            // (rentang 1 hari), jadi tetap ke-route S3/Bentuk C dengan
            // benar; mode=bulanan/rentang TETAP jadi mode itu di semua
            // chunk, gak peduli kebetulan ada chunk yang lebarnya 1 hari
            // di batas bulan.
            $data = $this->laporanDataService->generate(
                mode: $filter['mode'],
                loketId: $filter['loket_id'],
                tanggalMulai: $mulaiBulan,
                tanggalSelesai: $selesaiBulan,
                tellerId: $filter['teller_id'],
                actor: $request->user(),
                paginasi: false,
                forceRefresh: $forceRefresh,
            );
            $adaBridgeGagal = $adaBridgeGagal || $data['bridge_gagal'];

            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($this->namaSheetBulan($mulaiBulan));

            $filterSheet = $filter;
            $filterSheet['tanggal_mulai'] = $mulaiBulan;
            $filterSheet['tanggal_akhir'] = $selesaiBulan;

            $this->tulisSheet($sheet, $data, $filterSheet, $sertakanKolomLoket);
        }

        ActivityLog::catat($request->user(), $aksiLog, null, null, [
            'mode' => $filter['mode'],
            'loket_id' => $filter['loket_id'],
            'tanggal_mulai' => $filter['tanggal_mulai'],
            'tanggal_akhir' => $filter['tanggal_akhir'],
            'teller_id' => $filter['teller_id'],
            'jumlah_sheet' => count($rentangBulan),
        ]);

        $writer = new Xlsx($spreadsheet);
        // 'semua-loket' di namaFile() TETAP relevan di sini -- bukan
        // jaring pengaman buat kasus ambigu lagi (itu udah gak akan
        // kejadian di download() sejak guard di atas), tapi jadi nama
        // file yang memang BENAR buat export() Bentuk A (semua loket
        // itu skenario sah buat /export, cuma bukan buat /download).
        $filename = $this->namaFile($filter);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Pecah [$tanggalMulai, $tanggalSelesai] jadi daftar rentang PER
     * BULAN KALENDER -- chunk pertama/terakhir dipotong pas ke batas
     * request asli (BUKAN selalu tanggal 1 s/d akhir bulan penuh),
     * chunk di tengah (kalau ada) selalu 1 bulan kalender utuh.
     * Contoh: 15 Jan - 10 Mar -> [15-31 Jan, 1-28 Feb, 1-10 Mar].
     * Request dalam 1 bulan (termasuk mode=harian/bulanan yang memang
     * SELALU dalam 1 bulan) otomatis balikin cuma 1 chunk -- fungsi ini
     * gak perlu tau apa mode aslinya.
     *
     * @return list<array{0:string,1:string}>
     */
    private function pecahJadiRentangBulan(string $tanggalMulai, string $tanggalSelesai): array
    {
        $kursor = Carbon::parse($tanggalMulai)->startOfDay();
        $akhir = Carbon::parse($tanggalSelesai)->startOfDay();

        $chunks = [];
        while ($kursor->lessThanOrEqualTo($akhir)) {
            $akhirBulanIni = $kursor->copy()->endOfMonth()->startOfDay();
            $akhirChunk = $akhirBulanIni->greaterThan($akhir) ? $akhir->copy() : $akhirBulanIni;

            $chunks[] = [$kursor->toDateString(), $akhirChunk->toDateString()];
            $kursor = $akhirChunk->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * "September 2026" -- kalau cuma 1 sheet total (request dalam 1
     * bulan), tetap dikasih nama bulan (bukan generik "Laporan") biar
     * konsisten, sesuai diminta ("ada nama bulannya di sheetnya").
     */
    private function namaSheetBulan(string $tanggalDalamBulanItu): string
    {
        return $this->tanggalIndo($tanggalDalamBulanItu, 'F Y');
    }

    private function tulisSheet(Worksheet $sheet, array $data, array $filterSheet, bool $sertakanKolomLoket): void
    {
        $baris = $this->tulisHeaderFilter($sheet, $filterSheet);
        $baris++;

        $baris = match ($data['bentuk']) {
            'A' => $this->tulisBentukA($sheet, $baris, $data, $sertakanKolomLoket),
            'B' => $this->tulisBentukB($sheet, $baris, $data),
            'C' => $this->tulisBentukC($sheet, $baris, $data),
        };

        // Auto-width DIPASANG SEBELUM baris catatan di bawah -- kalimat
        // catatan itu panjang, kalau ikut terhitung bakal melebarkan
        // kolom A secara berlebihan.
        $kolomTerakhir = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $kolomTerakhir; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        if ($data['bridge_gagal']) {
            $baris++;
            $sheet->setCellValue("A{$baris}", "Catatan: sebagian data dari sistem SOPP lama gagal dimuat untuk periode ini. Baris/kolom yang terdampak ditandai 'Gagal dimuat', tidak diikutkan di Total Semua.");
        }
    }

    private function tulisHeaderFilter(Worksheet $sheet, array $filter): int
    {
        $baris = 1;

        $sheet->setCellValue("A{$baris}", 'FILTER PERIODE:');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true);
        // Dicek dari LEBAR TANGGAL di sini (bukan $mode) -- BEDA kelas
        // dari keputusan routing Bentuk B/C di atas. Ini murni soal
        // tampilan teks (hindari "31 Jan 2026 s/d 31 Jan 2026" yang
        // redundan buat chunk bulan yang kebetulan cuma nyisa 1 hari),
        // gak nentuin logic/data apapun -- jadi aman dicek dari lebar
        // tanggal tanpa reproduksi bug yang sama kayak di routing.
        $sheet->setCellValue("B{$baris}", $filter['tanggal_mulai'] === $filter['tanggal_akhir']
            ? $this->tanggalIndo($filter['tanggal_mulai'])
            : $this->tanggalIndo($filter['tanggal_mulai']).' s/d '.$this->tanggalIndo($filter['tanggal_akhir']));
        $baris++;

        if ($filter['loket_id'] !== null) {
            $sheet->setCellValue("A{$baris}", 'FILTER LOKET:');
            $sheet->getStyle("A{$baris}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$baris}", Loket::find($filter['loket_id'])?->nama_loket ?? '-');
            $baris++;
        }

        if ($filter['teller_id'] !== null) {
            $sheet->setCellValue("A{$baris}", 'FILTER TELLER:');
            $sheet->getStyle("A{$baris}")->getFont()->setBold(true);
            // Cuma id-nya yang kepegang di sini (nama teller ada di
            // response data, bukan di $filter) -- cukup buat jejak audit
            // file, FE/layar yang nampilin nama lengkapnya ke user.
            $sheet->setCellValueExplicit("B{$baris}", (string) $filter['teller_id'], DataType::TYPE_STRING);
            $baris++;
        }

        return $baris;
    }

    /**
     * @return int baris berikutnya yang masih kosong setelah section ini
     */
    private function tulisBentukA(Worksheet $sheet, int $baris, array $data, bool $sertakanKolomLoket): int
    {
        $vendorRoot = $data['vendors'];
        // Kolom label pertama SELALU ada posisinya (biar baris TOTAL
        // SEMUA -- yang selalu nulis ke kolom pertama, lihat
        // tulisBarisTotal() -- gak nyasar nimpa kolom angka vendor
        // pertama kalau $sertakanKolomLoket=false). "minus kolom Loket"
        // di /download diimplementasikan sebagai DIKOSONGKAN, bukan
        // dihilangkan strukturnya -- lihat OPEN ITEM di docblock kelas
        // soal Bentuk A + /download yang belum ada konfirmasi eksplisit.
        $baris = $this->tulisHeaderBertingkat($sheet, $baris, $sertakanKolomLoket ? 'Loket' : '', $vendorRoot);

        foreach ($data['items'] as $item) {
            $kolom = array_merge(
                [$sertakanKolomLoket ? $item['nama_loket'] : ''],
                array_map(fn (string $v) => $item['vendors'][$v] ?? 0, $vendorRoot),
                [$item['total_pendapatan_sopp'] ?? 'Gagal dimuat'],
                array_values($item['metode_setoran']),
                [
                    $item['total_setor'],
                    $item['selisih'] ?? 'Gagal dimuat',
                ],
            );
            $this->tulisBaris($sheet, $baris, $kolom);
            $baris++;
        }

        // total_row DARI RESPONS generate() ini apa adanya -- karena
        // generate() sekarang dipanggil per-bulan (lihat docblock
        // kelas), total_row ini OTOMATIS udah scope ke 1 bulan doang,
        // bukan perlu dihitung ulang manual di sini.
        $this->tulisBarisTotal($sheet, $baris, $data['total_row'], $vendorRoot);

        return $baris + 1;
    }

    private function tulisBentukB(Worksheet $sheet, int $baris, array $data): int
    {
        $vendorRoot = $data['vendors'];
        $baris = $this->tulisHeaderBertingkat($sheet, $baris, 'Tanggal', $vendorRoot);

        // Hari tanpa transaksi bridge & tanpa setoran sudah dibuang di
        // LaporanDataService::bentukB(), jadi $data['items'] di sini
        // langsung ditulis apa adanya.
        foreach ($data['items'] as $item) {
            $kolom = array_merge(
                [$this->tanggalIndo($item['tanggal'])],
                array_map(fn (string $v) => $item['vendors'][$v] ?? 0, $vendorRoot),
                [$item['total_pendapatan_sopp'] ?? 'Gagal dimuat'],
                array_values($item['metode_setoran']),
                [
                    $item['total_setor'],
                    $item['selisih'] ?? 'Gagal dimuat',
                ],
            );
            $this->tulisBaris($sheet, $baris, $kolom);
            $baris++;
        }

        $this->tulisBarisTotal($sheet, $baris, $data['total_row'], $vendorRoot);

        return $baris + 1;
    }

    /**
     * Bentuk C: 1 baris ringkasan (shape sama kayak Bentuk B, cuma 1
     * hari) + rincian_transaksi per metode di bawahnya. TANPA atribusi
     * teller per baris (keputusan final §A) -- kolom nama teller SENGAJA
     * tidak ada di rincian, konsisten sama shape API-nya.
     */
    private function tulisBentukC(Worksheet $sheet, int $baris, array $data): int
    {
        // Di Bentuk C, $data['vendors'] itu map nama_vendor => nominal
        // (atau null kalau bridge gagal), BUKAN daftar nama vendor
        // seperti di Bentuk A/B -- jadi yang dipakai sebagai kolom
        // adalah key-nya.
        $vendorRoot = array_keys($data['vendors'] ?? []);
        $baris = $this->tulisHeaderBertingkat($sheet, $baris, 'Tanggal', $vendorRoot);

        $kolom = array_merge(
            [$this->tanggalIndo($data['tanggal'])],
            array_map(fn (string $v) => $data['vendors'][$v] ?? 0, $vendorRoot),
            [$data['total_pendapatan_sopp'] ?? 'Gagal dimuat'],
            array_values($data['metode_setoran']),
            [
                $data['total_setor'],
                $data['selisih'] ?? 'Gagal dimuat',
            ],
        );
        $this->tulisBaris($sheet, $baris, $kolom);
        $baris += 2;

        foreach (self::KOLOM_METODE_SETORAN as $key => $label) {
            $entries = $data['rincian_transaksi'][$key] ?? [];
            if (empty($entries)) {
                continue;
            }

            $sheet->setCellValue("A{$baris}", "RINCIAN {$label}");
            $sheet->getStyle("A{$baris}")->getFont()->setBold(true);
            $baris++;
            $this->tulisBaris($sheet, $baris, ['Nominal (Rp)', 'Keterangan', 'Nama Karyawan', 'Identitas Karyawan', 'Konfirmasi', 'Link Bukti']);
            $this->gayaHeader($sheet, "A{$baris}:F{$baris}");
            $baris++;

            foreach ($entries as $entry) {
                $this->tulisBaris($sheet, $baris, [
                    $entry['nominal'] ?? 0,
                    $entry['keterangan'] ?? '',
                    $entry['nama_karyawan'] ?? '',
                    '',
                    ($entry['is_konfirmasi'] ?? false) ? 'Ya' : 'Tidak',
                    $entry['foto_bukti_url'] ?? '',
                ]);
                // identitas_karyawan dipaksa teks -- kalau berupa angka
                // panjang (NIK/HP) Excel akan menampilkannya sebagai
                // 1.23E+15 dan membuang digit di belakang.
                $sheet->setCellValueExplicit("D{$baris}", (string) ($entry['identitas_karyawan'] ?? ''), DataType::TYPE_STRING);
                $baris++;
            }
            $baris++;
        }

        return $baris;
    }

    /**
     * Header 2 baris: kategori (TRANSAKSI / DISETOR) di baris atas,
     * nama vendor/metode di baris bawah. Kolom tunggal di-merge vertikal.
     * Urutan kolom HARUS sama dengan urutan nilai yang ditulis di
     * tiap tulisBentuk*(): label, vendor..., JUMLAH TOTAL, metode...,
     * JUMLAH DISETOR, SELISIH.
     *
     * @param  list<string>  $vendorRoot
     * @return int baris pertama setelah header (data mulai dari sini)
     */
    private function tulisHeaderBertingkat(Worksheet $sheet, int $baris, string $labelPertama, array $vendorRoot): int
    {
        $baris2 = $baris + 1;
        $sel = fn (int $kol, int $br) => Coordinate::stringFromColumnIndex($kol).$br;

        $vertikal = function (int $kol, string $label) use ($sheet, $baris, $baris2, $sel) {
            $sheet->setCellValue($sel($kol, $baris), $label);
            $sheet->mergeCells($sel($kol, $baris).':'.$sel($kol, $baris2));
        };

        $grup = function (int $kol, string $judul, array $sub) use ($sheet, $baris, $baris2, $sel) {
            $sheet->setCellValue($sel($kol, $baris), $judul);
            if (count($sub) > 1) {
                $sheet->mergeCells($sel($kol, $baris).':'.$sel($kol + count($sub) - 1, $baris));
            }
            foreach (array_values($sub) as $i => $label) {
                $sheet->setCellValue($sel($kol + $i, $baris2), $label);
            }
        };

        $kol = 1;
        $vertikal($kol++, $labelPertama);

        if ($vendorRoot !== []) {
            $grup($kol, 'TRANSAKSI (RP)', $vendorRoot);
            $kol += count($vendorRoot);
        }

        $vertikal($kol++, 'JUMLAH TOTAL');

        $grup($kol, 'DISETOR (RP)', array_values(self::KOLOM_METODE_SETORAN));
        $kol += count(self::KOLOM_METODE_SETORAN);

        $vertikal($kol++, 'JUMLAH DISETOR');
        $vertikal($kol++, 'SELISIH (RP)');

        $this->gayaHeader($sheet, $sel(1, $baris).':'.$sel($kol - 1, $baris2));

        return $baris + 2;
    }

    private function gayaHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /**
     * @param  list<string>  $vendorRoot
     */
    private function tulisBarisTotal(Worksheet $sheet, int $baris, array $totalRow, array $vendorRoot): void
    {
        // 'TOTAL SEMUA' selalu gantiin apa pun yang biasanya ada di kolom
        // pertama baris data (nama Loket di Bentuk A, tanggal di Bentuk
        // B/C) -- sama seperti pola di RiwayatSetoranController::export().
        // "SEMUA" di sini artinya "semua baris DI SHEET INI" (1 bulan),
        // BUKAN semua bulan yang diminta -- karena tiap sheet sekarang
        // independen (lihat docblock kelas).
        $kolom = array_merge(
            ['TOTAL SEMUA'],
            array_map(fn (string $v) => $totalRow['vendors'][$v] ?? 0, $vendorRoot),
            [$totalRow['total_pendapatan_sopp'] ?? 'Gagal dimuat'],
            array_values($totalRow['metode_setoran']),
            [
                $totalRow['total_setor'],
                $totalRow['selisih'] ?? 'Gagal dimuat',
            ],
        );

        $this->tulisBaris($sheet, $baris, $kolom);

        $akhir = Coordinate::stringFromColumnIndex(count($kolom));
        $sheet->getStyle("A{$baris}:{$akhir}{$baris}")->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /**
     * @param  list<mixed>  $nilaiPerKolom  sequential, kolom A/B/C/... sesuai urutan array
     */
    private function tulisBaris(Worksheet $sheet, int $baris, array $nilaiPerKolom): void
    {
        foreach (array_values($nilaiPerKolom) as $i => $nilai) {
            $sel = Coordinate::stringFromColumnIndex($i + 1).$baris;
            $sheet->setCellValue($sel, $nilai);

            // Tanpa format eksplisit Excel pakai General, dan kolom
            // sempit menampilkan angka besar sebagai 1.9E+06.
            if (is_int($nilai) || is_float($nilai)) {
                $sheet->getStyle($sel)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
    }

    /**
     * `->translatedFormat()` polos gak otomatis ikut `config('app.locale')`
     * -- Carbon punya locale sendiri, TERPISAH dari locale Laravel, dan
     * gak ada `Carbon::setLocale()` global di project ini (dicek
     * langsung, gak ada satupun panggilan di app/Providers). Tanpa
     * `->locale('id')` eksplisit di SETIAP instance, hasilnya "10 August
     * 2026" (default Carbon = Inggris), bukan "10 Agustus 2026" --
     * padahal seluruh UI app ini Indonesia. `->locale()` per-instance
     * (bukan `Carbon::setLocale()` global) SENGAJA dipilih supaya gak
     * ninggalin efek samping ke kode lain yang kebetulan jalan di
     * request yang sama.
     */
    private function tanggalIndo(string $tanggal, string $format = 'd M Y'): string
    {
        return Carbon::parse($tanggal)->locale('id')->translatedFormat($format);
    }

    private function namaFile(array $filter): string
    {
        $namaLoket = $filter['loket_id'] !== null
            ? Str::slug(Loket::find($filter['loket_id'])?->nama_loket ?? 'loket')
            : 'semua-loket';

        $periode = $filter['mode'] === 'harian'
            ? $filter['tanggal_mulai']
            : "{$filter['tanggal_mulai']}_sd_{$filter['tanggal_akhir']}";

        return "laporan_{$namaLoket}_{$periode}.xlsx";
    }

    /**
     * Sama persis dengan LaporanController::authorizeAksesHalaman() --
     * duplikasi 3 baris ini sengaja TIDAK ditarik ke trait (beda dari
     * validasi filter), konsisten sama pola HakAksesController/
     * LogAktivitasController yang masing-masing juga punya method
     * private sendiri buat ini.
     */
    private function authorizeAksesHalaman(Request $request): void
    {
        $user = $request->user();
        if (! $user->is_superadmin && ! $user->bisa_akses_laporan) {
            abort(403, 'Tidak memiliki akses ke halaman ini.');
        }
    }
}
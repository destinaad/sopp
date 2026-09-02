/**
 * Data dummy "Data Pendapatan SOPP" yang muncul di halaman Input Setoran.
 * SENGAJA dibuat lebih dari 3 provider (termasuk yang nominalnya 0) untuk
 * membuktikan tampilannya benar-benar loop dinamis — bukan hardcode 3 nama
 * provider tertentu. Provider bernominal 0 otomatis digabung ke "Lain-lain"
 * lewat groupVendorDenganLainLain() (lihat utils/vendorMapping.js), jadi
 * kalau providernya bertambah/berkurang di database SOPP asli, UI tidak
 * perlu diubah kodenya sama sekali.
 *
 * Nanti diganti hasil fetch dari sistem SOPP yang sebenarnya.
 */
export const dataPendapatanSOPP = [
  { provider: "Finnet", kategori: "Telkom, PLN", nominal: 2205776 },
  { provider: "PUSKUD", kategori: "PLN, PDAM, Pulsa, PKB, PBB", nominal: 11159293 },
  { provider: "JNE", kategori: "Ongkir JNE", nominal: 46000 },
  { provider: "BRIVA", kategori: "Virtual Account BRI", nominal: 0 },
  { provider: "Pegadaian", kategori: "Cicilan Emas", nominal: 0 },
];

export function getTotalPendapatanSOPP() {
  return dataPendapatanSOPP.reduce((sum, row) => sum + row.nominal, 0);
}

/**
 * [PELUNASAN] Data dummy selisih per tanggal, dipakai di halaman Pelunasan
 * Setoran Lama. Key = tanggal (format ISO "YYYY-MM-DD"), value = nominal selisih.
 * Nanti diganti hasil fetch dari riwayat setoran yang sebenarnya.
 */
export const selisihRecords = {
  "2026-06-28": 50000,
  "2026-06-27": 9880000,
};

/**
 * [RIWAYAT] Data dummy tabel di halaman Riwayat Setoran (6 baris pertama
 * sesuai desain). Nanti diganti hasil fetch riwayat setoran yang sebenarnya,
 * lengkap dengan pagination dari backend (bukan cuma slice array di frontend).
 *
 * Status dipecah jadi 2 field yang INDEPENDEN satu sama lain, bukan 1 field
 * gabungan — karena "sudah submit tapi belum lunas" (submit duluan, dilunasi
 * belakangan lewat fitur Pelunasan) dan "masih draft tapi kebetulan sudah
 * lunas" (nominal sudah cocok, tapi belum ditekan submit) itu SAMA-SAMA
 * kombinasi yang wajar terjadi, bukan kategori yang saling meniadakan:
 * - statusWorkflow: "draft" | "submitted"
 * - statusKeuangan: "lunas" | "belum-lunas"
 */
export const riwayatSetoranList = [
  { id: "SET-20260701", tanggal: "2026-07-01", totalPendapatan: 12500000, transfer: 8000000, qris: 2000000, tunai: 1500000, kpay: 1000000, selisih: 0, statusWorkflow: "submitted", statusKeuangan: "lunas" },
  { id: "SET-20260630", tanggal: "2026-06-30", totalPendapatan: 8750000, transfer: 5000000, qris: 1250000, tunai: 2000000, kpay: 500000, selisih: 0, statusWorkflow: "submitted", statusKeuangan: "lunas" },
  { id: "SET-20260629", tanggal: "2026-06-29", totalPendapatan: 15200000, transfer: 10000000, qris: 3000000, tunai: 1000000, kpay: 1200000, selisih: 0, statusWorkflow: "submitted", statusKeuangan: "lunas" },
  { id: "SET-20260628", tanggal: "2026-06-28", totalPendapatan: 9100000, transfer: 4000000, qris: 1500000, tunai: 3000000, kpay: 600000, selisih: 150000, statusWorkflow: "submitted", statusKeuangan: "belum-lunas" },
  { id: "SET-20260627", tanggal: "2026-06-27", totalPendapatan: 11400000, transfer: 7500000, qris: 2000000, tunai: 1000000, kpay: 900000, selisih: 0, statusWorkflow: "submitted", statusKeuangan: "lunas" },
  { id: "SET-20260626", tanggal: "2026-06-26", totalPendapatan: 7200000, transfer: 4000000, qris: 1200000, tunai: 1500000, kpay: 500000, selisih: 0, statusWorkflow: "draft", statusKeuangan: "lunas" },
];

// [RIWAYAT] Total data & halaman dari backend (bukan dihitung dari
// riwayatSetoranList.length, karena di dunia nyata cuma 1 halaman yang
// benar-benar di-fetch, sisanya 142 data ada di database)
export const totalDataRiwayat = 142;
export const totalHalamanRiwayat = 24;

/**
 * [INPUT SETORAN] Cek status setoran untuk satu tanggal tertentu — dipakai
 * saat teller memilih tanggal transaksi lewat kalender di halaman Input
 * Setoran. Sumber data SEMENTARA masih dari riwayatSetoranList (mock) yang
 * sama dengan yang dipakai halaman Riwayat Setoran, supaya konsisten:
 * kalau sebuah tanggal statusnya "submitted" di Riwayat, maka di sini juga
 * bakal kebaca "sudah ada setoran".
 *
 * Return salah satu dari:
 * - "kosong"   -> belum ada entry setoran sama sekali untuk tanggal ini,
 *                 form Input Setoran boleh aktif & diisi dari nol.
 * - "terkunci" -> SUDAH ada entry setoran tercatat (workflow apa pun —
 *                 baik "draft" maupun "submitted", karena begitu ada baris
 *                 tercatat, tanggal itu dianggap "sudah dipakai"), form
 *                 harus tampil ter-lock (read only) supaya teller tidak
 *                 bikin entry dobel. Kalau memang niatnya melunasi selisih,
 *                 arahkan lewat card "Pelunasan Setoran Lama", BUKAN form ini.
 *
 * Nanti diganti fetch sungguhan ke BE, misal:
 *   GET /api/teller/setoran/status?tanggal=2026-07-24
 * yang me-return { status: "kosong" | "terkunci", data: {...} }
 */
export function getStatusSetoranTanggal(tanggal) {
  const existing = riwayatSetoranList.find((row) => row.tanggal === tanggal);
  if (!existing) {
    return { status: "kosong", data: null };
  }
  return { status: "terkunci", data: existing };
}

/**
 * [RIWAYAT] 3 kartu ringkasan di bagian bawah halaman Riwayat Setoran.
 */
export const ringkasanRiwayat = {
  totalTerverifikasi: 2450000000,
  persenVsBulanLalu: 12.5,
  totalEntryBulanIni: 124,
  rataRataEntryPerHari: 4.5,
  pendingSelisih: 150000,
};

/**
 * [DETAIL] Data dummy halaman Detail Setoran. Fungsi `getDetailSetoran(id)`
 * SEMENTARA selalu mengembalikan data yang sama berapa pun id-nya (belum ada
 * backend) — nanti diganti fetch sungguhan berdasarkan id (mis. dari
 * bridge.php?action=detail_setoran&id=...).
 */
const detailSetoranContoh = {
  id: "SET-20260701",
  tanggal: "2026-07-01",
  loket: "SOPP Blimbing",
  petugas: "Budi Santoso",
  pendapatan: [
    { provider: "FINNET", layanan: "PLN, Telkom, PDAM", nominal: 2205776 },
    { provider: "PUSKUD", layanan: "PLN, PDAM, Pulsa, PKB, PBB", nominal: 11159293 },
    { provider: "JNE", layanan: "Ongkir JNE", nominal: 46000 },
    { provider: "BRIVA", layanan: "Virtual Account BRI", nominal: 0 },
  ],
  ringkasanSetoran: { transfer: 10000000, qris: 540000, tunai: 2500000, kpay: 371069 },
  lampiran: {
    transfer: [
      { label: "#1", nominal: 6000000, buktiUrl: "#" },
      { label: "#2", nominal: 4000000, buktiUrl: "#" },
    ],
    qris: [{ label: "Settlement 1 Juli", nominal: 540000, buktiUrl: "#" }],
    tunai: [{ label: "Serah Terima Fisik", nominal: 2500000, buktiUrl: "#" }],
    kpayKeterangan: "Potong tabungan karyawan untuk setoran piutang...",
    kpayKaryawan: [
      { nama: "Ani Wijaya", nik: "20210499", nominal: 200000 },
      { nama: "Supardi", nik: "20190122", nominal: 171069 },
    ],
  },
  keteranganTeller: { waktu: "2026-07-01T15:30:00", catatan: "" },
};

export function getDetailSetoran(id) {
  return { ...detailSetoranContoh, id };
}
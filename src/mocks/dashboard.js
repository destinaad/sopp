/**
 * Data dummy ringkasan (summary cards) di dashboard teller.
 * Nanti tinggal ganti pemanggilan ini dengan hasil fetch API.
 *
 * statusSetoran di sini adalah STATUS WORKFLOW, sesuai kontrak API §7.1:
 * "draft" | "submitted" | "belum_input".
 */
export const dashboardSummary = {
  pendapatanHariIni: 13000000,
  persenVsKemarin: 8.2, // bisa juga null (lihat SummaryCards.jsx untuk penanganannya)
  statusSetoran: "belum_input", // "draft" | "submitted" | "belum_input"
  selisih: 0,
};

/**
 * Data dummy tabel "Aktivitas Setoran Terakhir".
 * status di sini adalah STATUS KEUANGAN ("lunas" | "belum_lunas"), BUKAN status
 * workflow — tabel ini isinya baris yang sudah pasti disetor (statusSetoran
 * "belum_input" tidak akan pernah muncul di sini), jadi yang relevan
 * ditampilkan adalah kecocokan nominalnya, bukan progres pengisian.
 *
 * ✅ 8 baris — disamakan dengan per_page Riwayat Setoran (kontrak API §7.1).
 */
export const riwayatSetoranTerakhir = [
  { tanggal: "2026-07-24", totalPendapatan: 13411069, totalSetor: 13411069, selisih: 0, status: "lunas" },
  { tanggal: "2026-07-23", totalPendapatan: 12500000, totalSetor: 12500000, selisih: 0, status: "lunas" },
  { tanggal: "2026-07-22", totalPendapatan: 15210000, totalSetor: 15210000, selisih: 0, status: "lunas" },
  { tanggal: "2026-07-21", totalPendapatan: 11450000, totalSetor: 11400000, selisih: -50000, status: "belum_lunas" },
  { tanggal: "2026-07-20", totalPendapatan: 9880000, totalSetor: 9880000, selisih: 0, status: "lunas" },
  { tanggal: "2026-07-19", totalPendapatan: 14300500, totalSetor: 14300500, selisih: 0, status: "lunas" },
  { tanggal: "2026-07-18", totalPendapatan: 10120000, totalSetor: 10070000, selisih: -50000, status: "belum_lunas" },
  { tanggal: "2026-07-17", totalPendapatan: 12980000, totalSetor: 12980000, selisih: 0, status: "lunas" },
];

export const totalEntriRiwayat = 30;
/**
 * Data dummy halaman Dashboard Verifikasi (Super Admin). Nanti diganti hasil
 * fetch bridge.php berdasarkan tanggal yang dipilih di dropdown.
 *
 * `status` di sini adalah STATUS WORKFLOW (draft | submitted | belum_input),
 * BUKAN status keuangan (lunas/belum-lunas). Judul tabel "Status Setoran per
 * Loket" merujuk ke progres pengisian teller, bukan ke kecocokan nominal —
 * kecocokan nominal itu sudah terwakili lewat kolom Selisih (merah/hijau).
 *
 *  - "belum_input" → row belum ada sama sekali, teller belum mulai isi apa-apa
 *  - "draft"        → teller sudah isi & simpan, tapi belum submit
 *  - "submitted"    → teller sudah submit
 */
export const loketStatusList = [
  { no: 1, id: "SET-B1", loket: "SOPP Blimbing", teller: "Budi Santoso", totalPendapatan: 13411069, totalSetor: 13411069, selisih: 0, status: "submitted" },
  { no: 2, id: "SET-L2", loket: "SOPP Lowokwaru", teller: "Siti Aminah", totalPendapatan: 10032000, totalSetor: 10032000, selisih: 0, status: "submitted" },
  { no: 3, id: "SET-K3", loket: "SOPP Klojen", teller: "Rudi Hartono", totalPendapatan: 11640000, totalSetor: 11590000, selisih: 50000, status: "submitted" },
  { no: 4, id: "SET-S4", loket: "SOPP Sukun", teller: "Dewi Lestari", totalPendapatan: 7328000, totalSetor: 0, selisih: 7328000, status: "belum_input" },
  { no: 5, id: "SET-KD5", loket: "SOPP Kedungkandang", teller: "Agus Prasetyo", totalPendapatan: 10135000, totalSetor: 10135000, selisih: 0, status: "submitted" },
  { no: 6, id: "SET-D6", loket: "SOPP Dinoyo", teller: "Nur Halimah", totalPendapatan: 6222000, totalSetor: 6222000, selisih: 0, status: "draft" },
  { no: 7, id: "SET-T7", loket: "SOPP Tlogomas", teller: "Eko Wibowo", totalPendapatan: 8530000, totalSetor: 0, selisih: 8530000, status: "belum_input" },
  { no: 8, id: "SET-M8", loket: "SOPP Merjosari", teller: "Fitriani", totalPendapatan: 7827000, totalSetor: 7827000, selisih: 0, status: "submitted" },
];

export const totalLoketTerdaftar = 11;

/**
 * Ringkasan 4 kartu di Dashboard Verifikasi. Bukan angka bebas — dihitung
 * dari loketStatusList (sudah_submit = status "submitted", belum_setor =
 * status "belum_input"), kecuali totalLoket yang memang jumlah loket
 * terdaftar di sistem (bisa lebih banyak dari yang sudah lapor hari ini).
 *
 * Catatan: baris berstatus "draft" tidak dihitung sebagai "sudah submit"
 * ataupun "belum setor" — dia progres di tengah, makanya jumlah
 * sudahSubmit + belumSetor bisa lebih kecil dari totalData kalau ada draft.
 */
export function getRingkasanDashboardAdmin() {
  const sudahSubmit = loketStatusList.filter((r) => r.status === "submitted").length;
  const belumSetor = loketStatusList.filter((r) => r.status === "belum_input").length;
  const adaSelisih = loketStatusList.filter((r) => r.selisih > 0).length;

  return {
    totalLoket: totalLoketTerdaftar,
    sudahSubmit,
    belumSetor,
    adaSelisih,
  };
}
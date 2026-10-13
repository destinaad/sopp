const BULAN = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

const HARI = [
  "Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu",
];

/**
 * Ubah objek Date (atau string ISO) jadi "9 Juli 2026"
 */
export function formatDate(date) {
  const d = date instanceof Date ? date : new Date(date);
  return `${d.getDate()} ${BULAN[d.getMonth()]} ${d.getFullYear()}`;
}

/**
 * Ubah objek Date (atau string ISO) jadi "Senin, 1 Juli 2026" (dipakai di header dashboard)
 */
export function formatDateWithDay(date) {
  const d = date instanceof Date ? date : new Date(date);
  return `${HARI[d.getDay()]}, ${formatDate(d)}`;
}

/**
 * Ubah objek Date (atau string ISO) jadi "01 Juli 2026" (tanggal 2-digit,
 * dipakai di tabel Riwayat Setoran — beda dari formatDate() yang tidak
 * zero-pad tanggal 1 digit)
 */
export function formatTanggalPanjang(date) {
  const d = date instanceof Date ? date : new Date(date);
  const tanggal = String(d.getDate()).padStart(2, "0");
  return `${tanggal} ${BULAN[d.getMonth()]} ${d.getFullYear()}`;
}
/**
 * Ubah angka (number) jadi format Rupiah, contoh: 13411069 -> "Rp 13.411.069"
 * Bisa terima number ataupun number negatif (untuk selisih minus).
 */
export function formatRupiah(value) {
  const isNegative = value < 0;
  const absValue = Math.abs(value);
  const formatted = absValue.toLocaleString("id-ID");
  return `${isNegative ? "- " : ""}Rp ${formatted}`;
}

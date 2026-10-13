/**
 * Tanggal hari ini menurut jam LOKAL browser, format YYYY-MM-DD.
 *
 * JANGAN pakai new Date().toISOString().slice(0, 10): toISOString() selalu
 * UTC, jadi di WIB (UTC+7) antara 00.00-07.00 hasilnya masih tanggal kemarin.
 */
export function tanggalLokal(d = new Date()) {
  const p = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

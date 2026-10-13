/**
 * Terjemahkan field mentah dari API jadi bentuk user state aplikasi.
 * Sesuai API contract terbaru (POST /api/v1/auth/login & GET /api/v1/me):
 * Tidak ada kolom role di database, akses fitur dikendalikan langsung oleh flag
 * kapabilitas boolean independen (capability-based).
 */
export function mapUserDariApi(apiUser) {
  if (!apiUser) return null;

  const isSuperadmin = Boolean(apiUser.is_superadmin);
  const isVerifikator = [
    "bisa_akses_laporan",
    "bisa_atur_hak_akses",
    "bisa_lihat_log",
    "bisa_input_setoran_semua_loket",
    "bisa_edit_setoran_semua_loket",
  ].some((capability) => Boolean(apiUser[capability]));

  return {
    name: apiUser.nama,
    role: isSuperadmin
      ? "superadmin"
      : isVerifikator
        ? "verifikator"
        : "teller",
    loket: apiUser.nama_loket || "-",
    loket_id: apiUser.loket_id,
    is_superadmin: isSuperadmin,
    bisa_akses_laporan: Boolean(apiUser.bisa_akses_laporan),
    bisa_atur_hak_akses: Boolean(apiUser.bisa_atur_hak_akses),
    bisa_lihat_log: Boolean(apiUser.bisa_lihat_log),
    bisa_input_setoran_semua_loket: Boolean(apiUser.bisa_input_setoran_semua_loket),
    bisa_edit_setoran_semua_loket: Boolean(apiUser.bisa_edit_setoran_semua_loket),
  };
}

/**
 * Menentukan path dashboard default berdasarkan kapabilitas user.
 */
export function getDefaultDashboard(user) {
  if (!user) return "/login";

  // /admin/dashboard di App.jsx HANYA boleh dibuka superadmin atau yang punya
  // bisa_akses_laporan / bisa_edit_setoran_semua_loket. Kalau path ini
  // dikembalikan untuk user lain, RoleGuard melempar balik ke sini lagi
  // -> redirect berputar, halaman kosong.
  if (user.is_superadmin || user.bisa_akses_laporan || user.bisa_edit_setoran_semua_loket) {
    return "/admin/dashboard";
  }

  // Teller biasa (terikat loket). Rute ini tanpa syarat kapabilitas.
  if (user.loket_id) return "/teller/dashboard";

  // Tanpa loket tapi punya kapabilitas non-dashboard: arahkan ke halaman
  // kapabilitasnya, bukan ke dashboard.
  if (user.bisa_atur_hak_akses) return "/admin/hak-akses";
  if (user.bisa_lihat_log) return "/admin/log";

  // Tanpa kapabilitas apa pun = user biasa (teller). Setiap user seharusnya
  // punya loket, jadi tujuannya tetap dashboard teller. Kalau loket_id ternyata
  // null (data akun bermasalah), BE membalas 403 "Akun ini tidak terhubung ke
  // loket manapun." dan pesan itu ditampilkan di dashboard teller. Aman dari
  // redirect berputar: rute /teller/dashboard tidak punya syarat kapabilitas.
  return "/teller/dashboard";
}

// Fallback untuk backward compatibility jika ada modul yang belum termigrasi
export const DASHBOARD_PER_ROLE = {
  teller: "/teller/dashboard",
  verifikator: "/verifikator/dashboard",
  superadmin: "/admin/dashboard",
};
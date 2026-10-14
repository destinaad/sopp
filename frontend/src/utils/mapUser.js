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

export function getDefaultDashboard(user) {
  if (!user) return "/login";

  if (user.is_superadmin || user.bisa_akses_laporan || user.bisa_edit_setoran_semua_loket) {
    return "/admin/dashboard";
  }

  if (user.loket_id) return "/teller/dashboard";

  if (user.bisa_atur_hak_akses) return "/admin/hak-akses";
  if (user.bisa_lihat_log) return "/admin/log";

  return "/teller/dashboard";
}

export const DASHBOARD_PER_ROLE = {
  teller: "/teller/dashboard",
  verifikator: "/verifikator/dashboard",
  superadmin: "/admin/dashboard",
};
/**
 * Terjemahkan field mentah dari API jadi bentuk { name, role, loket }
 * yang dipakai authStore & seluruh komponen.
 *
 * Aturan role:
 *  - is_superadmin true → "admin"
 *  - ada kapabilitas admin (salah satu bisa_*) → "verifikator"
 *  - selain itu → "teller"
 */
export function mapUserDariApi(apiUser) {
  const adaKapabilitasAdmin =
    apiUser.bisa_akses_laporan ||
    apiUser.bisa_atur_hak_akses ||
    apiUser.bisa_lihat_log ||
    apiUser.bisa_input_setoran_semua_loket ||
    apiUser.bisa_edit_setoran_semua_loket;

  let role = "teller";
  if (apiUser.is_superadmin) role = "admin";
  else if (adaKapabilitasAdmin) role = "verifikator";

  return {
    name: apiUser.nama,
    role,
    loket: apiUser.nama_loket || "-",
    loket_id: apiUser.loket_id,
    bisa_input_setoran_semua_loket: apiUser.bisa_input_setoran_semua_loket,
    bisa_edit_setoran_semua_loket: apiUser.bisa_edit_setoran_semua_loket,
  };
}

export const DASHBOARD_PER_ROLE = {
  teller: "/teller/dashboard",
  verifikator: "/verifikator/dashboard",
  admin: "/admin/dashboard",
};
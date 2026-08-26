// Auth API service (dummy implementation for now)
// Exports functions: verifySSO(token), login(identifier, password), logout()
//
// Keep interface identical to future axios calls so swapping to real requests
// is trivial. Real implementation nanti (Tahap 4), sesuai kontrak API §6.1-6.2:
//
//   export async function verifySSO(token) {
//     await getCsrfCookie();
//     const res = await api.post("/sso/verify", { token });
//     return res.data;
//   }
//
// Urutan WAJIB: getCsrfCookie() dulu, baru POST /sso/verify — bukan GET,
// token dikirim di body request, BUKAN sebagai parameter URL.

import api, { getCsrfCookie } from "@/lib/axios";

/**
 * Kontrak API §6.2 — bentuk response WAJIB dibungkus { user: {...} },
 * bukan field mentah langsung di top-level data.
 *
 * Field kapabilitas ikut "aturan OR" (§6.2, penting buat siapa pun yang
 * baca data ini nanti): bisa_akses_laporan / bisa_atur_hak_akses /
 * bisa_lihat_log SELALU true kalau is_superadmin true (di-OR-kan backend),
 * TIDAK PERNAH false untuk superadmin walau kolom aslinya false. Sebaliknya,
 * bisa_input_setoran_semua_loket & bisa_edit_setoran_semua_loket dikirim
 * APA ADANYA tanpa di-OR — superadmin TIDAK otomatis dianggap bisa input/edit
 * setoran semua loket kecuali memang di-grant eksplisit lewat Hak Akses.
 * Data dummy di bawah sudah mengikuti pola ini (is_superadmin false, semua
 * bisa_* false — konsisten, tidak perlu penyesuaian manual).
 */
export async function verifySSO(token) {
  // Dummy success response following contract
  const user = {
    nama: "Budi Santoso",
    loket_id: 3,
    is_superadmin: false,
    bisa_akses_laporan: false,
    bisa_atur_hak_akses: false,
    bisa_lihat_log: false,
    bisa_input_setoran_semua_loket: false,
    bisa_edit_setoran_semua_loket: false,
  };

  return Promise.resolve({ status: "ok", data: { user } });
}

export async function login(identifier, password) {
  // Dummy: accept any identifier/password for now
  const user = {
    nama: identifier || "Teller Demo",
    loket_id: 3,
    is_superadmin: false,
    bisa_akses_laporan: false,
    bisa_atur_hak_akses: false,
    bisa_lihat_log: false,
    bisa_input_setoran_semua_loket: false,
    bisa_edit_setoran_semua_loket: false,
  };

  return Promise.resolve({ status: "ok", data: { user } });
}

export async function logout() {
  // Beritahu BE bahwa user keluar — ini yang matiin sesi di server
  const res = await api.post("/auth/logout");
  return res.data;
}
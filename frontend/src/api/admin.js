import api from "@/lib/axios";

// ═════════════════════════════════════════════════════════════════════════════
// 1. DASHBOARD ADMIN
// Endpoint: GET /api/admin/dashboard?tanggal=YYYY-MM-DD
// ═════════════════════════════════════════════════════════════════════════════

export async function getDashboardAdmin(tanggal, refresh = false) {
  const res = await api.get("/api/v1/admin/dashboard", {
    params: { tanggal, refresh: refresh ? "true" : undefined },
  });
  return res.data;
}


// ═════════════════════════════════════════════════════════════════════════════
// 2. LAPORAN
// Endpoint: GET /api/admin/laporan
// ═════════════════════════════════════════════════════════════════════════════

export async function getLaporan(params = {}) {
  const res = await api.get("/api/v1/admin/laporan", { params });
  return res.data;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. LAPORAN
// Endpoint: GET /api/admin/laporan
// ═════════════════════════════════════════════════════════════════════════════
// Mengunduh seluruh hasil filter sebagai file .xlsx

export async function exportLaporan(params = {}) {
  const res = await api.get("/api/v1/admin/laporan/export", {
    params,
    responseType: "blob",
  });
  return res;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. LAPORAN
// Mengunduh laporan untuk 1 loket spesifik sebagai file .xlsx
// ═════════════════════════════════════════════════════════════════════════════

export async function downloadLaporanLoket(params = {}) {
  const res = await api.get("/api/v1/admin/laporan/download", {
    params,
    responseType: "blob",
  });
  return res;
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. HAK AKSES
// Endpoint GET: GET /api/admin/hak-akses
// Endpoint PUT: PUT /api/admin/hak-akses
// ═════════════════════════════════════════════════════════════════════════════

export async function getHakAkses(params = {}) {
  const res = await api.get("/api/v1/admin/hak-akses", { params });
  return res.data;
}

export async function simpanHakAkses(payload) {
  const body = Array.isArray(payload) ? { perubahan: payload } : payload;
  const res = await api.put("/api/v1/admin/hak-akses", body);
  return res.data;
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. LOG AKTIVITAS
// Endpoint: GET /api/admin/log-aktivitas
// params = { page, per_page, user, aksi, tanggal_mulai, tanggal_akhir }
// ═════════════════════════════════════════════════════════════════════════════

export async function getLogAktivitas(params = {}) {
  const res = await api.get("/api/v1/admin/log-aktivitas", { params });
  return res.data;
}

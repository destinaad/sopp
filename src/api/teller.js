import { dashboardSummary, riwayatSetoranTerakhir } from "@/mocks/dashboard";
import { dataPendapatanSOPP, getDetailSetoran as mockGetDetailSetoran, selisihRecords, riwayatSetoranList, totalDataRiwayat, totalHalamanRiwayat, ringkasanRiwayat } from "@/mocks/setoran";

// Note: these functions return Promises that resolve to objects matching
// the API contract: { status: 'ok' | 'error', data: ... }
// When backend is ready, replace dummy body with axios calls e.g. api.get(...)

export async function getDashboard() {
  const payload = {
    nama_teller: "Budi Santoso",
    nama_loket: "SOPP Blimbing",
    tanggal_transaksi: new Date().toISOString().slice(0, 10),
    pendapatan_sopp: 13411069,
    pendapatan_sopp_status: null, // null = bridge berhasil; can be 'gagal_dimuat'
    persentase_vs_kemarin: 8.2,
    status_setoran: "draft",
    selisih: 0,
    ada_selisih: false,
    fetched_at: new Date().toISOString().replace("T", " ").slice(0, 19),
    riwayat_terakhir: riwayatSetoranTerakhir || [],
  };

  return Promise.resolve({ status: "ok", data: payload });
}

export async function getSetoranHariIni(options = {}) {
  // support ?refresh=1 but it's dummy so ignore
  const payload = {
    pendapatan: dataPendapatanSOPP,
    total_pendapatan: dataPendapatanSOPP.reduce((s, r) => s + r.nominal, 0),
    fetched_at: new Date().toISOString(),
  };

  return Promise.resolve({ status: "ok", data: payload });
}

export async function getSisaPelunasan(tanggal) {
  const value = selisihRecords[tanggal] ?? 0;
  return Promise.resolve({ status: "ok", data: { tanggal, sisa_pelunasan: value } });
}

export async function getRiwayat(params = {}) {
  const page = Number(params.page) || 1;
  const per_page = 8;
  const items = riwayatSetoranList;
  const totalItems = totalDataRiwayat || items.length;
  const meta = {
    current_page: page,
    per_page,
    total_items: totalItems,
    total_pages: totalHalamanRiwayat || Math.max(1, Math.ceil(totalItems / per_page)),
  };

  return Promise.resolve({ status: "ok", data: { items, meta, ringkasan: ringkasanRiwayat || {} } });
}

export async function getDetailSetoran(id) {
  const detail = mockGetDetailSetoran(id);
  return Promise.resolve({ status: "ok", data: detail });
}

// Password change
export async function gantiPassword(password) {
  // Dummy: validate length
  if (!password || password.length < 6) {
    const error = {
      response: {
        status: 422,
        data: { status: "error", message: "Validation failed", errors: { password: ["Minimal 6 karakter"] } },
      },
    };
    return Promise.reject(error);
  }

  return Promise.resolve({ status: "ok", data: null });
}

// Input Setoran operations (names chosen to be clear and stable)
export async function createSetoranEntry(entry) {
  // entry: { provider, nominal, ... } — accept any object and echo id
  const created = { ...entry, id: `ENTRY-${Date.now()}` };
  return Promise.resolve({ status: "ok", data: created });
}

export async function updateSetoranEntry(id, updates) {
  // Dummy: echo back merged
  const updated = { id, ...updates };
  return Promise.resolve({ status: "ok", data: updated });
}

export async function deleteSetoranEntry(id) {
  return Promise.resolve({ status: "ok", data: { id } });
}

export async function saveDraftSetoran(payload) {
  // payload: full draft
  return Promise.resolve({ status: "ok", data: { draft_id: `DRAFT-${Date.now()}`, ...payload } });
}

export async function submitSetoran(payload) {
  // payload: full setoran to submit
  return Promise.resolve({ status: "ok", data: { submitted: true, id: `SET-${Date.now()}` } });
}

export async function createPelunasan(tanggal, nominal) {
  // Dummy: accept and echo
  return Promise.resolve({ status: "ok", data: { tanggal, nominal, pelunasan_id: `PEL-${Date.now()}` } });
}

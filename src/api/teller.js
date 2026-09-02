import api from "@/lib/axios";

export async function getDashboard() {
  const res = await api.get("/api/v1/teller/dashboard");
  return res.data;
}

export async function getFormSetoran(params = {}) {
  const res = await api.get("/api/v1/teller/setoran", { params });
  return res.data;
}

/**
 * Endpoint: GET /api/v1/teller/setoran/kalender (Card A §4).
 * Dipakai buat date-picker: nunjukin status tiap tanggal dalam sebulan
 * (belum_input/draft/submitted) supaya teller bisa lihat sekilas tanggal
 * mana yang masih perlu diisi. Panggil SEKALI per bulan yang ditampilkan
 * di date-picker (bukan per-tanggal), hasilnya sudah gap-filled untuk
 * seluruh hari dalam bulan itu.
 *
 * PENTING (§4): endpoint ini scope-nya PERSONAL (status setoran user yang
 * login sendiri), BUKAN status keseluruhan loket.
 *
 * params: { loket_id?: number, bulan: string } — bulan WAJIB, format Y-m (mis. "2026-08")
 */
export async function getKalenderSetoran(params) {
  const res = await api.get("/api/v1/teller/setoran/kalender", { params });
  return res.data;
}

/**
 * Kedua fungsi di bawah ini menerima `formData` yang SUDAH dalam bentuk
 * FormData final sesuai kontrak (array flat entries[], field
 * metode_pembayaran/nominal/foto_bukti/dst) — lihat
 * utils/buildSetoranFormData.js untuk proses "penerjemahan" dari bentuk
 * {transfer, qris, tunai, kpay} yang dipakai RincianSetoran.jsx.
 *
 * Dikirim sebagai multipart/form-data (bukan JSON) karena entries bisa
 * membawa file foto_bukti per baris — axios otomatis set Content-Type
 * yang benar kalau body-nya FormData, jadi tidak perlu diset manual.
 *
 * Endpoint: POST /api/v1/teller/setoran/draft (Card A §2)
 * Endpoint: POST /api/v1/teller/setoran/submit (Card A §3)
 */
export async function saveDraftSetoran(formData) {
  const res = await api.post("/api/v1/teller/setoran/draft", formData);
  return res.data;
}

export async function submitSetoran(formData) {
  const res = await api.post("/api/v1/teller/setoran/submit", formData);
  return res.data;
}

export async function getPelunasan(params = {}) {
  const res = await api.get("/api/v1/teller/setoran/pelunasan", { params });
  return res.data;
}

export async function getRiwayat(params = {}) {
  const res = await api.get("/api/v1/teller/setoran/riwayat", { params });
  return res.data;
}

export async function deleteRiwayat(id, alasan = null) {
  const res = await api.delete(`/api/v1/teller/setoran/riwayat/${id}`, {
    data: alasan ? { alasan } : {},
  });
  return res.data;
}

export async function exportRiwayat(params = {}) {
  const res = await api.get("/api/v1/teller/setoran/riwayat/export", { 
    params, 
    responseType: "blob",
  });
  return res;
} 

/**
 * Endpoint: GET /api/v1/teller/setoran/{id} (Detail Setoran §1).
 * Hanya pernah beroperasi di row berstatus submitted — row draft (termasuk
 * draft milik sendiri) selalu 404 di endpoint ini.
 */
export async function getDetailSetoran(id) {
  const res = await api.get(`/api/v1/teller/setoran/${id}`);
  return res.data;
}

/**
 * Endpoint: PUT /api/v1/teller/setoran/{id} (Detail Setoran §2).
 * Full replace entries row ini — upsert + delete-by-omission (hard delete).
 * `formData` harus sudah termasuk `entries_updated_at` (WAJIB present, boleh
 * kosong) — lihat buildSetoranFormData dan DetailSetoran.jsx.
 * Dikirim sebagai multipart/form-data karena ada file upload per-entry.
 */
export async function updateSetoran(id, formData) {
  const res = await api.put(`/api/v1/teller/setoran/${id}`, formData);
  return res.data;
}

export async function gantiPassword(password) {
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

export async function submitPelunasan(formData, params = {}) {
  const res = await api.post("/api/v1/teller/setoran/pelunasan", formData, { params });
  return res.data;
}

/** 
 * Endpoint: GET /api/v1/lokets
 * Mengambil daftar semua loket, diurutkan alfabetis nama_loket
 * Syarat akses: cukup sudah login
 * 
 * Response:{ status: "ok", data: [{ loket_id, nama_loket }, ...]}
 * data selalu array
 */

export async function getLokets() {
  const res = await api.get("/api/v1/lokets");
  return res.data;
}
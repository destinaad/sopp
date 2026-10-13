import api from "@/lib/axios";

export async function getDashboard() {
  const res = await api.get("/api/v1/teller/dashboard");
  return res.data;
}

export async function getFormSetoran(params = {}) {
  const res = await api.get("/api/v1/teller/setoran", { params });
  return res.data;
}

export async function getKalenderSetoran(params) {
  const res = await api.get("/api/v1/teller/setoran/kalender", { params });
  return res.data;
}

export async function saveDraftSetoran(formData, params = {}) {
  const res = await api.post("/api/v1/teller/setoran/draft", formData, { params });
  return res.data;
}

export async function submitSetoran(formData, params = {}) {
  const res = await api.post("/api/v1/teller/setoran/submit", formData, { params });
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

export async function getDetailSetoran(id) {
  const res = await api.get(`/api/v1/teller/setoran/${id}`);
  return res.data;
}

export async function updateSetoran(id, formData) {
  const payload = new FormData();
  for (const [key, value] of formData.entries()) {
    payload.append(key, value);
  }
  payload.append("_method", "PUT");
  const res = await api.post(`/api/v1/teller/setoran/${id}`, payload);
  return res.data;
}

export async function gantiPassword(password, passwordConfirmation) {
  const res = await api.put("/api/v1/me/password", {
    password,
    password_confirmation: passwordConfirmation,
  });
  return res.data;
}

export async function submitPelunasan(formData, params = {}) {
  const res = await api.post("/api/v1/teller/setoran/pelunasan", formData, { params });
  return res.data;
}

export async function getLokets() {
  const res = await api.get("/api/v1/lokets");
  return res.data;
}
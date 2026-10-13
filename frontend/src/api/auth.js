import api, { getCsrfCookie } from "@/lib/axios";

export async function verifySSO(token) {
  // Sudah diubah ke API
  const res = await api.post("/api/v1/sso/verify", { token });
  return res.data;
}

export async function login(identifier, password) {
  // Sudah diubah ke API
  await getCsrfCookie();
  const res = await api.post("/api/v1/auth/login", { identifier, password });
  return res.data;
}

export async function logout() {
  // Sudah diubah ke API
  const res = await api.post("/api/v1/auth/logout");
  return res.data;
}

export async function getMe() {
  // Sudah diubah ke API
  const res = await api.get("/api/v1/me");
  return res.data;
}
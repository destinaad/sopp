import axios from "axios";

// Centralized axios instance for the app
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "http://localhost:8000",
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
});

// Request interceptor (optional place for future token handling)
api.interceptors.request.use(
  (config) => {
    // Keep configuration minimal for Laravel Sanctum (stateful)
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor: handle common HTTP status cases
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;

    // Notify app that session is unauthorized/expired
    if (status === 401) {
      // Dispatch a global event so auth layer / router can respond if present
      try {
        window.dispatchEvent(new CustomEvent("auth:unauthorized", { detail: error }));
      } catch (e) {
        // ignore if CustomEvent not available
        // eslint-disable-next-line no-console
        console.warn("auth:unauthorized event dispatch failed", e);
      }
    }

    // For 422 validation errors, let caller inspect error.response.data.errors
    // For other errors, propagate to caller
    return Promise.reject(error);
  }
);

// Panggil ini SEBELUM request auth pertama (SSO verify atau login manual),
// sesuai alur di kontrak API §6.1: "React baca token dari URL -> GET
// /sanctum/csrf-cookie -> POST /api/sso/verify...". Endpoint ini di LUAR
// prefix /api (baseURL axios di atas otomatis menambahkan /api ke semua
// request lain, makanya path di sini ditulis lengkap dari root, bukan
// relatif ke baseURL).
export async function getCsrfCookie() {
  const csrfBaseUrl = (import.meta.env.VITE_API_URL || "http://localhost:8000").replace(/\/api\/?$/, "");
  return axios.get(`${csrfBaseUrl}/sanctum/csrf-cookie`, { withCredentials: true });
}

export default api;
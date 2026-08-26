/**
 * src/api/admin.js
 *
 * Semua fungsi request untuk sisi Admin & Verifikator.
 *
 * MODE SEKARANG: fungsi return data dummy dari mocks/.
 * WAKTU BE SIAP: uncomment import api + axios call, hapus import mock.
 */

// import api from "@/lib/axios";

import {
  loketStatusList,
  getRingkasanDashboardAdmin,
  totalLoketTerdaftar,
} from "@/mocks/dashboardAdmin";
import { penggunaDummy } from "@/mocks/hakAkses";
import { logsDummy } from "@/mocks/logAktivitas";

// ═════════════════════════════════════════════════════════════════════════════
// 1. DASHBOARD ADMIN
// Endpoint: GET /api/admin/dashboard?tanggal=YYYY-MM-DD
// ═════════════════════════════════════════════════════════════════════════════

export async function getDashboardAdmin(tanggal) {
  await new Promise((r) => setTimeout(r, 600));
  return {
    status: "ok",
    data: {
      loket_status: loketStatusList,
      ringkasan: getRingkasanDashboardAdmin(),
      total_loket: totalLoketTerdaftar,
    },
  };
  // const res = await api.get("/api/admin/dashboard", { params: { tanggal } });
  // return res.data;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. LAPORAN
// Endpoint: GET /api/admin/laporan
// ═════════════════════════════════════════════════════════════════════════════

export async function getLaporan(params = {}) {
  await new Promise((r) => setTimeout(r, 600));
  return {
    status: "ok",
    data: { items: [], meta: { current_page: 1, per_page: 10, total_items: 0, total_pages: 0 } },
  };
  // const res = await api.get("/api/admin/laporan", { params });
  // return res.data;
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. HAK AKSES
// Endpoint GET: GET /api/admin/hak-akses
// Endpoint PUT: PUT /api/admin/hak-akses
// ═════════════════════════════════════════════════════════════════════════════

export async function getHakAkses() {
  await new Promise((r) => setTimeout(r, 600));
  return { status: "ok", data: { pengguna: penggunaDummy } };
  // const res = await api.get("/api/admin/hak-akses");
  // return res.data;
}

export async function simpanHakAkses(payload) {
  await new Promise((r) => setTimeout(r, 700));
  console.log("[DUMMY] simpanHakAkses, payload:", payload);
  return { status: "ok", data: null };
  // const res = await api.put("/api/admin/hak-akses", { perubahan: payload });
  // return res.data;
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. LOG AKTIVITAS
// Endpoint: GET /api/admin/log-aktivitas
// params = { page, per_page, user, aksi, tanggal_mulai, tanggal_akhir }
// ═════════════════════════════════════════════════════════════════════════════

export async function getLogAktivitas(params = {}) {
  await new Promise((r) => setTimeout(r, 600));

  // Filter dummy di FE — simulasi perilaku BE
  let hasil = [...logsDummy];

  if (params.search) {
    const q = params.search.toLowerCase();
    hasil = hasil.filter(
      (l) =>
        l.user.toLowerCase().includes(q) ||
        l.loket.toLowerCase().includes(q) ||
        l.target.toLowerCase().includes(q)
    );
  }

  if (params.aksi && params.aksi !== "semua") {
    hasil = hasil.filter((l) => l.aksi === params.aksi);
  }

  const perPage = params.per_page ?? 10;
  const page    = params.page ?? 1;
  const total   = hasil.length;
  const items   = hasil.slice((page - 1) * perPage, page * perPage);

  return {
    status: "ok",
    data: {
      items,
      meta: {
        current_page: page,
        per_page: perPage,
        total_items: total,
        total_pages: Math.max(1, Math.ceil(total / perPage)),
      },
    },
  };

  // ── Swap waktu BE siap ──
  // const res = await api.get("/api/admin/log-aktivitas", { params });
  // return res.data;
}
/**
 * src/pages/admin/LogAktivitas.jsx
 *
 * Perubahan dari versi sebelumnya:
 *  - Data tidak lagi hardcode — diambil dari getLogAktivitas() di api/admin.js
 *  - Filter (search + aksi) dikirim sebagai params ke getLogAktivitas()
 *  - Paginasi dikendalikan dari state page yang dikirim ke API
 *  - Tambah loading state + error state
 *  - Filter bar sekarang tampil di UI (sebelumnya tidak ada)
 */

import { useState, useEffect, useCallback } from "react";
import { getLogAktivitas } from "@/api/admin";
import {
  IconMagnifier,
  IconRefresh,
  IconChevronLeftOutline,
  IconChevronRightOutline,
  IconSpinner,
} from "@/components/ui/Icon";

// ─────────────────────────────────────────────────────────────
// Konstanta
// ─────────────────────────────────────────────────────────────

const PAGE_SIZE = 10;

const ROLE_LABEL = {
  teller:   "Teller",
  keuangan: "Keuangan",
  admin:    "Super Admin",
};

const ROLE_STYLE = {
  teller:   { bg: "#e6f1fb", border: "#185fa5", text: "#185fa5" },
  keuangan: { bg: "#fef3c7", border: "#f59e0b", text: "#92400e" },
  admin:    { bg: "#ffdad6", border: "#ba1a1a", text: "#93000a" },
};

const AKSI_STYLE = {
  "ADMIN ACTION":  { bg: "#ffdad6", border: "#ba1a1a", text: "#93000a" },
  "INPUT SETORAN": { bg: "#e6f1fb", border: "#185fa5", text: "#185fa5" },
  "EDIT SETORAN":  { bg: "#faeeda", border: "#854f0b", text: "#854f0b" },
  APPROVE:         { bg: "#e1f5ee", border: "#006c49", text: "#006c49" },
  SUBMIT:          { bg: "#e1f5ee", border: "#006c49", text: "#006c49" },
  REVISION:        { bg: "#ffdad6", border: "#ba1a1a", text: "#93000a" },
  LOGIN:           { bg: "#f0e6fb", border: "#7c3aed", text: "#6d28d9" },
};

const AKSI_OPTIONS = [
  "ADMIN ACTION",
  "INPUT SETORAN",
  "EDIT SETORAN",
  "APPROVE",
  "SUBMIT",
  "REVISION",
  "LOGIN",
];

// ─────────────────────────────────────────────────────────────
// Komponen kecil
// ─────────────────────────────────────────────────────────────

function Pill({ label, style, dot = false }) {
  return (
    <div
      className="relative inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl"
      style={{ backgroundColor: style.bg }}
    >
      <div
        aria-hidden
        className="absolute inset-0 rounded-xl pointer-events-none border"
        style={{ borderColor: style.border }}
      />
      {dot && <div className="size-1.5 rounded-full" style={{ backgroundColor: style.text }} />}
      <span className="text-[10px] font-bold tracking-wider uppercase" style={{ color: style.text }}>
        {label}
      </span>
    </div>
  );
}

function PerubahanCell({ perubahan }) {
  const tampil = perubahan.slice(0, 2);
  const sisa   = perubahan.length - tampil.length;

  return (
    <div className="flex flex-col gap-0.5">
      {tampil.map((p, i) => (
        <span key={i} className="text-xs text-[#424751]">
          {p.label && <span className="text-[#727782]">{p.label}: </span>}
          {p.before && (
            <>
              <span className="line-through text-[#9ca3af]">{p.before}</span>
              <span className="mx-1 text-[#9ca3af]">→</span>
            </>
          )}
          <span className="font-semibold text-[#191c20]">{p.after}</span>
        </span>
      ))}
      {sisa > 0 && (
        <span className="text-[11px] font-semibold text-[#710014] w-fit">
          +{sisa} lainnya
        </span>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Halaman Utama
// ─────────────────────────────────────────────────────────────

export default function LogAktivitas() {
  // ── Data & loading ──
  const [items, setItems]   = useState([]);
  const [meta, setMeta]     = useState({ current_page: 1, per_page: PAGE_SIZE, total_items: 0, total_pages: 1 });
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);

  // ── Filter (diketik user, baru dikirim saat Apply/Enter) ──
  const [searchInput, setSearchInput]   = useState("");
  const [filterAksiInput, setFilterAksiInput] = useState("semua");

  // ── Params aktif yang benar-benar dikirim ke API ──
  const [params, setParams] = useState({ search: "", aksi: "semua", page: 1, per_page: PAGE_SIZE });

  // ── Fetch ──
  const fetchLog = useCallback((p) => {
    setLoading(true);
    setError(null);
    getLogAktivitas(p)
      .then((res) => {
        if (res?.status === "ok") {
          setItems(res.data.items);
          setMeta(res.data.meta);
        } else {
          setError(res?.message ?? "Gagal memuat log aktivitas.");
        }
      })
      .catch((err) => setError(err.message ?? "Terjadi kesalahan."))
      .finally(() => setLoading(false));
  }, []);

  // Fetch awal + setiap params berubah (ganti halaman atau terapkan filter)
  useEffect(() => {
    fetchLog(params);
  }, [params, fetchLog]);

  // ── Terapkan filter → reset ke page 1 ──
  const handleTerapkan = () => {
    setParams({ search: searchInput, aksi: filterAksiInput, page: 1, per_page: PAGE_SIZE });
  };

  // ── Reset filter ──
  const handleReset = () => {
    setSearchInput("");
    setFilterAksiInput("semua");
    setParams({ search: "", aksi: "semua", page: 1, per_page: PAGE_SIZE });
  };

  // ── Ganti halaman ──
  const handlePage = (p) => {
    setParams((prev) => ({ ...prev, page: p }));
  };

  const adaFilter = params.search || params.aksi !== "semua";
  const { current_page: page, total_pages: totalPages, total_items: totalItems } = meta;

  // Nomor halaman yang ditampilkan
  const nomorHalaman = Array.from({ length: Math.min(3, totalPages) }, (_, i) => i + 1);
  const tampilEllipsis      = totalPages > 4;
  const tampilHalamanTerakhir = totalPages > 3;

  return (
    <>
      {/* ── Page Header ── */}
      <div className="flex flex-col gap-0.5">
        <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Log Aktivitas</h1>
        <p className="text-[#424751] text-sm">
          Riwayat seluruh aksi yang terjadi di sistem SOPP Setoran.
        </p>
      </div>

      {/* ── Filter Bar ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 flex flex-wrap gap-3 items-end">
        {/* Cari */}
        <div className="flex flex-col gap-1 flex-1 min-w-[200px]">
          <label className="text-[#424751] text-xs font-medium">Cari</label>
          <div className="relative">
            <IconMagnifier className="absolute left-3 top-1/2 -translate-y-1/2 size-4" color="#727782" />
            <input
              type="text"
              placeholder="Nama user, loket, atau target..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && handleTerapkan()}
              className="w-full border border-[#d9d9d6] rounded pl-9 pr-3 py-2 text-sm text-[#191c20] placeholder-[#727782] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            />
          </div>
        </div>

        {/* Filter Aksi */}
        <div className="flex flex-col gap-1">
          <label className="text-[#424751] text-xs font-medium">Jenis Aksi</label>
          <select
            value={filterAksiInput}
            onChange={(e) => setFilterAksiInput(e.target.value)}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          >
            <option value="semua">Semua Aksi</option>
            {AKSI_OPTIONS.map((a) => (
              <option key={a} value={a}>{a}</option>
            ))}
          </select>
        </div>

        {/* Tombol Terapkan */}
        <button
          onClick={handleTerapkan}
          className="self-end px-4 py-2 rounded bg-[#710014] text-white text-sm font-semibold hover:bg-[#5a000f] transition-colors"
        >
          Terapkan
        </button>

        {/* Tombol Reset — hanya muncul kalau ada filter aktif */}
        {adaFilter && (
          <button
            onClick={handleReset}
            className="self-end flex items-center gap-1.5 border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
          >
            <IconRefresh className="size-4" />
            Reset
          </button>
        )}
      </div>

      {/* ── Tabel ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3 flex items-center justify-between">
          <span className="font-bold text-[#191c20] text-base">Riwayat Aktivitas</span>
          <span className="text-[#727782] text-xs font-semibold tracking-[0.5px]">
            {totalItems} aktivitas
          </span>
        </div>

        {/* Loading */}
        {loading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-[#727782] text-sm">
            <IconSpinner className="size-5" />
            <span>Memuat log aktivitas...</span>
          </div>
        ) : error ? (
          <div className="px-6 py-8 text-center text-[#93000a] text-sm">
            Gagal memuat data: {error}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px]">
              <thead>
                <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
                  {["Waktu", "User", "Loket", "Aksi", "Target", "Perubahan"].map((h) => (
                    <th
                      key={h}
                      className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-3 text-left whitespace-nowrap"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="text-center py-12 text-[#727782] text-sm">
                      Tidak ada aktivitas yang sesuai filter.
                    </td>
                  </tr>
                ) : (
                  items.map((log, i) => (
                    <tr
                      key={log.id}
                      className={`border-b border-[#f0f0ee] transition-colors hover:bg-[#f7f3f3] ${
                        i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"
                      }`}
                    >
                      {/* Waktu */}
                      <td className="px-4 py-3.5 whitespace-nowrap align-top">
                        <div className="text-sm font-semibold text-[#191c20]">{log.tanggal}</div>
                        <div className="text-xs text-[#727782]">{log.jam}</div>
                      </td>

                      {/* User + Role */}
                      <td className="px-4 py-3.5 whitespace-nowrap align-top">
                        <div className="text-sm font-semibold text-[#191c20]">{log.user}</div>
                        <div className="mt-1">
                          <Pill
                            label={ROLE_LABEL[log.role] ?? log.role}
                            style={ROLE_STYLE[log.role] ?? ROLE_STYLE.teller}
                          />
                        </div>
                      </td>

                      {/* Loket */}
                      <td className="px-4 py-3.5 text-sm text-[#424751] font-medium whitespace-nowrap align-top">
                        {log.loket}
                      </td>

                      {/* Badge Aksi */}
                      <td className="px-4 py-3.5 text-center align-top">
                        <div className="flex justify-center">
                          <Pill
                            label={log.aksi}
                            style={AKSI_STYLE[log.aksi] ?? { bg: "#f7f7f6", border: "#d9d9d6", text: "#424751" }}
                            dot
                          />
                        </div>
                      </td>

                      {/* Target */}
                      <td className="px-4 py-3.5 text-sm font-semibold text-[#191c20] whitespace-nowrap align-top font-mono">
                        {log.target}
                      </td>

                      {/* Perubahan */}
                      <td className="px-4 py-3.5 max-w-xs align-top">
                        <PerubahanCell perubahan={log.perubahan} />
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}

        {/* Paginasi */}
        {!loading && !error && (
          <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 px-6 py-4">
            <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
              Menampilkan{" "}
              {totalItems === 0 ? 0 : (page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, totalItems)}{" "}
              dari {totalItems} entri
            </span>

            <div className="flex items-center gap-1">
              <button
                onClick={() => handlePage(Math.max(1, page - 1))}
                disabled={page === 1}
                className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                <IconChevronLeftOutline className="size-3.5" />
                Sebelumnya
              </button>

              {nomorHalaman.map((p) => (
                <button
                  key={p}
                  onClick={() => handlePage(p)}
                  className={`size-8 flex items-center justify-center rounded text-xs font-semibold transition-colors ${
                    page === p
                      ? "bg-[#710014] text-white border border-[#710014]"
                      : "border border-[#d9d9d6] text-[#424751] hover:bg-[#f7f7f6]"
                  }`}
                >
                  {p}
                </button>
              ))}

              {tampilEllipsis && (
                <span className="px-1 text-[#727782] text-xs">...</span>
              )}

              {tampilHalamanTerakhir && (
                <button
                  onClick={() => handlePage(totalPages)}
                  className={`size-8 flex items-center justify-center rounded text-xs font-semibold transition-colors ${
                    page === totalPages
                      ? "bg-[#710014] text-white border border-[#710014]"
                      : "border border-[#d9d9d6] text-[#424751] hover:bg-[#f7f7f6]"
                  }`}
                >
                  {totalPages}
                </button>
              )}

              <button
                onClick={() => handlePage(Math.min(totalPages, page + 1))}
                disabled={page === totalPages}
                className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                Berikutnya
                <IconChevronRightOutline className="size-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
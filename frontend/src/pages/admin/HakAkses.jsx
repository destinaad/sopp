/**
 * src/pages/admin/HakAkses.jsx
 *
 * Sesuai kontrak API PUT /api/v1/admin/hak-akses §2:
 *  - Whitelist field `tipe` snake_case:
 *    bisa_akses_laporan, bisa_atur_hak_akses, bisa_lihat_log,
 *    bisa_input_setoran_semua_loket, bisa_edit_setoran_semua_loket
 *  - Kolom `role` dan `aktif` ditiadakan dari backend & UI
 *  - Paginasi memanggil API GET /api/v1/admin/hak-akses?page={page}
 */

import { useState, useEffect, useCallback } from "react";
import { getHakAkses, simpanHakAkses } from "@/api/admin";
import BannerNotif from "@/components/shared/BannerNotif";
import {
  IconShieldCheck,
  IconChevronLeftOutline,
  IconChevronRightOutline,
  IconWarningTriangle,
  IconSpinner,
} from "@/components/ui/Icon";

// ─────────────────────────────────────────────────────────────
// Label Permission (Snake-Case Sesuai API Whitelist)
// ─────────────────────────────────────────────────────────────

const PERM_LABEL = {
  bisa_akses_laporan:           "Akses Laporan",
  bisa_atur_hak_akses:          "Atur Hak Akses",
  bisa_lihat_log:               "Lihat Log Aktivitas",
  bisa_input_setoran_semua_loket: "Input Setoran Semua Loket",
  bisa_edit_setoran_semua_loket:  "Edit Setoran Semua Loket",
};

const PERM_COLS = Object.entries(PERM_LABEL).map(([key, label]) => ({ key, label }));

// ─────────────────────────────────────────────────────────────
// Toggle Switch
// ─────────────────────────────────────────────────────────────

function Toggle({ value, onChange, disabled = false }) {
  return (
    <button
      role="switch"
      aria-checked={value}
      onClick={() => !disabled && onChange(!value)}
      disabled={disabled}
      className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full
        transition-colors duration-200 focus:outline-none
        focus-visible:ring-2 focus-visible:ring-[#710014]/40
        ${value ? "bg-[#710014]" : "bg-[#c2c6d2]"}
        ${disabled ? "opacity-40 cursor-not-allowed" : "cursor-pointer"}
      `}
    >
      <span
        className={`inline-block size-5 rounded-full bg-white shadow-sm
          transition-transform duration-200
          ${value ? "translate-x-[22px]" : "translate-x-0.5"}
        `}
      />
    </button>
  );
}

// ─────────────────────────────────────────────────────────────
// Modal Konfirmasi Simpan
// ─────────────────────────────────────────────────────────────

function ModalKonfirmasi({ perubahan, menyimpan, onBatal, onSimpan }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden">
        <div className="flex items-center gap-3 px-6 py-4 border-b border-[#d9d9d6]">
          <IconWarningTriangle className="size-6" color="#854f0b" />
          <span className="font-bold text-[#191c20] text-lg">Konfirmasi Perubahan</span>
        </div>

        <div className="px-6 py-5 flex flex-col gap-4 max-h-[60vh] overflow-y-auto">
          <p className="text-[#424751] text-sm">
            Perubahan berikut akan disimpan ke sistem:
          </p>
          <ul className="text-sm text-[#191c20] flex flex-col gap-2">
            {perubahan.map((p, i) => (
              <li key={i} className="flex items-start gap-2 bg-[#f7f7f6] rounded-lg px-4 py-2.5">
                <span className="shrink-0 text-[#710014] font-bold">·</span>
                <span>
                  <strong>{p.nama}</strong>
                  {" — "}
                  <span className="text-[#424751]">{PERM_LABEL[p.tipe] ?? p.tipe}</span>
                  {": "}
                  <span className="font-semibold">{p.ke ? "Diizinkan" : "Dicabut"}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>

        <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-[#d9d9d6]">
          <button
            onClick={onBatal}
            disabled={menyimpan}
            className="px-5 py-2 rounded border border-[#d9d9d6] text-sm font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 transition-colors"
          >
            Batal
          </button>
          <button
            onClick={onSimpan}
            disabled={menyimpan}
            className="px-5 py-2 rounded bg-[#710014] text-white text-sm font-semibold hover:bg-[#5a000f] disabled:opacity-60 transition-colors flex items-center gap-2"
          >
            {menyimpan ? (
              <IconSpinner className="size-4" color="white" />
            ) : (
              <IconShieldCheck className="size-4" color="white" />
            )}
            {menyimpan ? "Menyimpan..." : "Simpan Perubahan"}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Halaman Utama
// ─────────────────────────────────────────────────────────────

export default function HakAkses() {
  const [daftarPengguna, setDaftarPengguna] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [menyimpan, setMenyimpan] = useState(false);

  const [perubahan, setPerubahan] = useState([]);
  const [modalTerbuka, setModalTerbuka] = useState(false);
  // { tipe: "error" | "peringatan", pesan: string } -- tampil sebagai banner
  // di dalam halaman (bukan alert() bawaan browser).
  const [notif, setNotif] = useState(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, per_page: 20, total_items: 0, total_pages: 1 });

  // ── Fetch data pengguna berdasarkan page dari API ──
  const muatData = useCallback(async (targetPage = 1) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getHakAkses({ page: targetPage });
      if (res?.status === "ok") {
        setDaftarPengguna(res.data.items ?? []);
        if (res.data.meta) {
          setMeta(res.data.meta);
        }
      } else {
        setError(res?.message ?? "Gagal memuat data pengguna.");
      }
    } catch (err) {
      const pesanBe = err?.response?.data?.message;
      setError(pesanBe || "Terjadi kesalahan saat memuat data.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    muatData(page);
  }, [page, muatData]);

  // ── Helper catat perubahan (hanya snake_case whitelist) ──
  const catatPerubahan = (id, nama, tipe, nilaiLama, nilaiBaru) => {
    if (nilaiLama === nilaiBaru) return;
    setPerubahan((prev) => {
      const idx = prev.findIndex((p) => p.id === id && p.tipe === tipe);
      if (idx !== -1) {
        const updated = [...prev];
        if (nilaiBaru === updated[idx].dari) {
          updated.splice(idx, 1);
        } else {
          updated[idx] = { ...updated[idx], ke: nilaiBaru };
        }
        return updated;
      }
      return [...prev, { id, nama, tipe, dari: nilaiLama, ke: nilaiBaru }];
    });
  };

  const togglePerm = (id, permKey) => {
    const p = daftarPengguna.find((u) => u.id === id);
    if (!p) return;
    const baru = !p[permKey];
    setNotif(null);
    catatPerubahan(id, p.nama, permKey, p[permKey], baru);
    setDaftarPengguna((prev) => prev.map((u) => u.id === id ? { ...u, [permKey]: baru } : u));
  };

  // ── Simpan perubahan ke API ──
  const handleSimpan = async () => {
    setMenyimpan(true);
    setNotif(null);
    try {
      // Body payload { perubahan: [...] } dikirim dengan tipe snake_case
      await simpanHakAkses(perubahan);
      setPerubahan([]);
      setModalTerbuka(false);
      // Refresh data halaman aktif
      muatData(page);
    } catch (err) {
      const status = err?.response?.status;
      const data = err?.response?.data;

      if (status === 409) {
        setNotif({
          tipe: "peringatan",
          pesan: data?.message
            ? `${data.message} Daftar sudah dimuat ulang otomatis; ulangi perubahan bila masih diperlukan.`
            : "Data sudah berubah sejak halaman dimuat. Daftar sudah dimuat ulang otomatis; ulangi perubahan bila masih diperlukan.",
        });
        setPerubahan([]);
        setModalTerbuka(false);
        muatData(page);
      } else if (status === 403) {
        setNotif({ tipe: "error", pesan: data?.message || "Tidak bisa mengubah hak akses milik sendiri." });
        handleBatalSemua();
        setModalTerbuka(false);
      } else if (data?.errors && Object.keys(data.errors).length > 0) {
        const pesanPertama = Object.values(data.errors).flat()[0];
        setNotif({ tipe: "error", pesan: pesanPertama || "Validasi gagal." });
        handleBatalSemua();
        setModalTerbuka(false);
      } else {
        setNotif({ tipe: "error", pesan: data?.message || "Gagal menyimpan perubahan. Coba lagi." });
        handleBatalSemua();
        setModalTerbuka(false);
      }
    } finally {
      setMenyimpan(false);
    }
  };

  // ── Batal semua — revert state ke sebelum perubahan ──
  const handleBatalSemua = () => {
    const revert = daftarPengguna.map((u) => ({ ...u }));
    perubahan.forEach(({ id, tipe, dari }) => {
      const idx = revert.findIndex((u) => u.id === id);
      if (idx === -1) return;
      revert[idx][tipe] = dari;
    });
    setDaftarPengguna(revert);
    setPerubahan([]);
  };

  const totalKolom = 3 + PERM_COLS.length;
  const totalPages = meta.total_pages || 1;
  const totalItems = meta.total_items ?? daftarPengguna.length;
  const perPage = meta.per_page ?? 20;

  // ── Loading state ──
  if (loading) {
    return (
      <div className="flex items-center justify-center gap-2 py-20 text-[#727782] text-sm">
        <IconSpinner className="size-5" />
        <span>Memuat data pengguna...</span>
      </div>
    );
  }

  // ── Error state ──
  if (error) {
    return (
      <div className="bg-[#ffdad6] border border-[#ba1a1a]/30 rounded-lg px-6 py-4 flex items-center justify-between text-[#93000a] text-sm">
        <span>Gagal memuat data: {error}</span>
        <button
          onClick={() => muatData(page)}
          className="border border-[#ba1a1a]/40 bg-white px-3 py-1.5 rounded text-xs font-medium text-[#93000a] hover:bg-[#ffdad6] transition-colors"
        >
          Coba lagi
        </button>
      </div>
    );
  }

  return (
    <>
      {modalTerbuka && (
        <ModalKonfirmasi
          perubahan={perubahan}
          menyimpan={menyimpan}
          onBatal={() => !menyimpan && setModalTerbuka(false)}
          onSimpan={handleSimpan}
        />
      )}

      {/* ── Page Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="flex flex-col gap-0.5">
          <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Hak Akses</h1>
          <p className="text-[#424751] text-sm">
            Kelola hak akses dan permission pengguna SOPP Setoran.
          </p>
        </div>

        {perubahan.length > 0 && (
          <div className="flex items-center gap-2 self-start flex-wrap">
            <span className="text-xs text-[#854f0b] font-semibold bg-[#faeeda] px-3 py-1.5 rounded-full border border-[#854f0b]/30">
              {perubahan.length} perubahan belum disimpan
            </span>
            <button
              onClick={handleBatalSemua}
              className="px-4 py-2 rounded border border-[#d9d9d6] text-sm font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] transition-colors"
            >
              Batal
            </button>
            <button
              onClick={() => setModalTerbuka(true)}
              className="px-4 py-2 rounded bg-[#710014] text-white text-sm font-semibold hover:bg-[#5a000f] transition-colors flex items-center gap-2"
            >
              <IconShieldCheck className="size-4" color="white" />
              Simpan
            </button>
          </div>
        )}
      </div>

      <BannerNotif notif={notif} onTutup={() => setNotif(null)} />

      {/* ── Tabel ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3 flex items-center justify-between">
          <span className="font-bold text-[#191c20] text-base">Daftar Pengguna & Hak Akses</span>
          <span className="text-[#727782] text-xs font-semibold tracking-[0.5px]">
            {totalItems} pengguna
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1000px]">
            <thead>
              <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
                <th colSpan={3} className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-2 text-center border-r border-[#d9d9d6]">
                  Identitas Pengguna
                </th>
                <th colSpan={PERM_COLS.length} className="font-semibold text-[#185fa5] text-xs tracking-[0.6px] uppercase px-4 py-2 text-center">
                  Permission / Hak Akses
                </th>
              </tr>

              <tr className="bg-[#fafafa] border-b border-[#d9d9d6]">
                {["#", "Nama", "Loket / Unit"].map((h, i) => (
                  <th key={h} className={`font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-3 text-left whitespace-nowrap ${i === 2 ? "border-r border-[#d9d9d6]" : ""}`}>
                    {h}
                  </th>
                ))}
                {PERM_COLS.map((col, i) => (
                  <th key={col.key} className={`font-semibold text-[#185fa5] text-xs tracking-[0.6px] uppercase px-4 py-3 text-center whitespace-nowrap ${i === PERM_COLS.length - 1 ? "" : "border-r border-[#d9d9d6]"}`}>
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>

            <tbody>
              {daftarPengguna.length === 0 ? (
                <tr>
                  <td colSpan={totalKolom} className="text-center py-12 text-[#727782] text-sm">
                    Tidak ada data pengguna.
                  </td>
                </tr>
              ) : (
                daftarPengguna.map((p, i) => {
                  const nomorUrut = (page - 1) * perPage + i + 1;
                  const adaPerubahan = perubahan.some((pr) => pr.id === p.id);
                  const rowBg = adaPerubahan ? "bg-[#fef3c7]" : i % 2 === 1 ? "bg-[#fafafa]" : "bg-white";

                  return (
                    <tr key={p.id} className={`border-b border-[#f0f0ee] transition-colors ${rowBg} hover:bg-[#f7f3f3]`}>
                      <td className="px-4 py-3 text-sm text-[#727782] w-8">{nomorUrut}</td>
                      <td className="px-4 py-3 text-sm font-semibold text-[#191c20] whitespace-nowrap">
                        {p.nama}
                        {adaPerubahan && (
                          <span className="ml-2 text-[10px] font-bold text-[#854f0b] bg-[#faeeda] px-1.5 py-0.5 rounded">
                            DIUBAH
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-sm text-[#424751] whitespace-nowrap border-r border-[#f0f0ee]">{p.loket || "-"}</td>

                      {PERM_COLS.map((col, ci) => (
                        <td key={col.key} className={`px-4 py-3 text-center ${ci === PERM_COLS.length - 1 ? "" : "border-r border-[#f0f0ee]"}`}>
                          <div className="flex justify-center">
                            <Toggle value={!!p[col.key]} onChange={() => togglePerm(p.id, col.key)} />
                          </div>
                        </td>
                      ))}
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Paginasi API */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-6 py-3 border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)]">
            <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
              Menampilkan {(page - 1) * perPage + 1}–{Math.min(page * perPage, totalItems)} dari {totalItems} pengguna
            </span>
            <div className="flex items-center gap-1">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                <IconChevronLeftOutline className="size-3.5" />
                Sebelumnya
              </button>
              <span className="px-3 py-1.5 text-xs font-semibold text-[#191c20]">
                {page} / {totalPages}
              </span>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                Berikutnya
                <IconChevronRightOutline className="size-3.5" />
              </button>
            </div>
          </div>
        )}

        {/* Footer ringkasan */}
        <div className="border-t border-[#d9d9d6] bg-[#f7f7f6] px-6 py-2">
          <span className="text-[#727782] text-xs">
            {daftarPengguna.filter((u) =>
              PERM_COLS.some((col) => u[col.key])
            ).length} dari {daftarPengguna.length} pengguna pada halaman ini memiliki akses tambahan
          </span>
        </div>
      </div>
    </>
  );
}
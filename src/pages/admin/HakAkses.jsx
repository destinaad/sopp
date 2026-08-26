/**
 * src/pages/admin/HakAkses.jsx
 *
 * Perubahan dari versi sebelumnya:
 *  - getHakAkses() sekarang return { status, data: { pengguna } } yang benar
 *  - handleSimpan() sekarang memanggil simpanHakAkses(perubahan) dari admin.js
 *  - Import PERM_COLS dari @/mocks/hakAkses (bukan hardcode di sini)
 *  - Loading state + error state ditangani dengan benar
 */

import { PERM_COLS, penggunaDummy } from "@/mocks/hakAkses";
import { useState, useMemo, useEffect } from "react";
import { getHakAkses, simpanHakAkses } from "@/api/admin";
import {
  IconShieldCheck,
  IconMagnifier,
  IconRefresh,
  IconChevronLeftOutline,
  IconChevronRightOutline,
  IconWarningTriangle,
  IconSpinner,
} from "@/components/ui/Icon";

// ─────────────────────────────────────────────────────────────
// Konstanta
// ─────────────────────────────────────────────────────────────

const PAGE_SIZE = 8;

const ROLE_LABEL = {
  teller:   "Teller",
  verifikator: "Verifikator",
  admin:    "Super Admin",
};

const ROLE_OPTIONS = [
  { value: "teller",   label: "Teller" },
  { value: "verifikator", label: "Verifikator" },
  { value: "admin",    label: "Super Admin" },
];

const PERM_LABEL = {
  bisaAksesLaporan:           "Akses Laporan",
  bisaAturHakAkses:           "Atur Hak Akses",
  bisaLihatLog:               "Lihat Log Aktivitas",
  bisaInputSetoranSemuaLoket: "Input Setoran Semua Loket",
  bisaEditSetoranSemuaLoket:  "Edit Setoran Semua Loket",
  aktif:                      "Status Aktif",
  role:                       "Role",
};

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
// Badge Aktif / Nonaktif
// ─────────────────────────────────────────────────────────────

function StatusAktifBadge({ aktif }) {
  return aktif ? (
    <div className="relative inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-[#e1f5ee]">
      <div aria-hidden className="absolute inset-0 rounded-xl pointer-events-none border border-[#006c49]" />
      <div className="size-2 rounded-full bg-[#006c49]" />
      <span className="text-xs font-semibold text-[#006c49] tracking-[0.5px]">Aktif</span>
    </div>
  ) : (
    <div className="relative inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-[#e1e2e9]">
      <div aria-hidden className="absolute inset-0 rounded-xl pointer-events-none border border-[#c2c6d2]" />
      <div className="size-2 rounded-full bg-[#727782]" />
      <span className="text-xs font-semibold text-[#424751] tracking-[0.5px]">Nonaktif</span>
    </div>
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
                  {p.tipe === "role" ? (
                    <>
                      <span className="font-semibold">{ROLE_LABEL[p.dari]}</span>
                      {" → "}
                      <span className="font-semibold">{ROLE_LABEL[p.ke]}</span>
                    </>
                  ) : p.tipe === "aktif" ? (
                    <span className="font-semibold">{p.ke ? "Aktif" : "Nonaktif"}</span>
                  ) : (
                    <span className="font-semibold">{p.ke ? "Diizinkan" : "Dicabut"}</span>
                  )}
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

  const [page, setPage] = useState(1);

  // ── Fetch data pengguna saat halaman pertama dibuka ──
  // Sengaja tidak pakai hook terpisah karena filter & paginasi
  // di halaman ini murni di FE — tidak dikirim sebagai params request.
  useEffect(() => {
    let batal = false;
    setLoading(true);
    setError(null);

    getHakAkses()
      .then((res) => {
        if (batal) return;
        if (res?.status === "ok") {
          setDaftarPengguna(res.data.pengguna);
        } else {
          setError(res?.message ?? "Gagal memuat data pengguna.");
        }
      })
      .catch((err) => {
        if (!batal) setError(err.message ?? "Terjadi kesalahan.");
      })
      .finally(() => {
        if (!batal) setLoading(false);
      });

    return () => { batal = true; };
  }, []);

  // ── Filter ──
  const rowsTerfilter = daftarPengguna;

  const totalPages = Math.ceil(rowsTerfilter.length / PAGE_SIZE);
  const rowsHalaman = rowsTerfilter.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  // ── Helper catat perubahan ──
  const catatPerubahan = (id, nama, tipe, nilaiLama, nilaiBaru) => {
    if (nilaiLama === nilaiBaru) return;
    setPerubahan((prev) => {
      const idx = prev.findIndex((p) => p.id === id && p.tipe === tipe);
      if (idx !== -1) {
        const updated = [...prev];
        if (nilaiBaru === nilaiLama) {
          updated.splice(idx, 1);
        } else {
          updated[idx] = { ...updated[idx], ke: nilaiBaru };
        }
        return updated;
      }
      return [...prev, { id, nama, tipe, dari: nilaiLama, ke: nilaiBaru }];
    });
  };

  const ubahRole = (id, roleKe) => {
    const p = daftarPengguna.find((u) => u.id === id);
    if (!p || p.role === roleKe) return;
    catatPerubahan(id, p.nama, "role", p.role, roleKe);
    setDaftarPengguna((prev) => prev.map((u) => u.id === id ? { ...u, role: roleKe } : u));
  };

  const toggleAktif = (id) => {
    const p = daftarPengguna.find((u) => u.id === id);
    if (!p) return;
    const baru = !p.aktif;
    catatPerubahan(id, p.nama, "aktif", p.aktif, baru);
    setDaftarPengguna((prev) => prev.map((u) => u.id === id ? { ...u, aktif: baru } : u));
  };

  const togglePerm = (id, permKey) => {
    const p = daftarPengguna.find((u) => u.id === id);
    if (!p) return;
    const baru = !p[permKey];
    catatPerubahan(id, p.nama, permKey, p[permKey], baru);
    setDaftarPengguna((prev) => prev.map((u) => u.id === id ? { ...u, [permKey]: baru } : u));
  };

  // ── Simpan — sekarang memanggil simpanHakAkses() dari api/admin.js ──
  const handleSimpan = async () => {
    setMenyimpan(true);
    try {
      await simpanHakAkses(perubahan);
      setPerubahan([]);
      setModalTerbuka(false);
    } catch (err) {
      console.error("Gagal simpan hak akses:", err);
      // Bisa tambah toast error di sini nanti
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

  const totalKolom = 4 + PERM_COLS.length + 1;

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
      <div className="bg-[#ffdad6] border border-[#ba1a1a]/30 rounded-lg px-6 py-4 text-[#93000a] text-sm">
        Gagal memuat data: {error}
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
            Kelola permission dan status aktif setiap pengguna SOPP Setoran.
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

      {/* ── Tabel ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3 flex items-center justify-between">
          <span className="font-bold text-[#191c20] text-base">Daftar Pengguna & Hak Akses</span>
          <span className="text-[#727782] text-xs font-semibold tracking-[0.5px]">
            {rowsTerfilter.length} pengguna
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1050px]">
            <thead>
              <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
                <th colSpan={4} className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-2 text-center border-r border-[#d9d9d6]">
                  Identitas Pengguna
                </th>
                <th colSpan={PERM_COLS.length} className="font-semibold text-[#185fa5] text-xs tracking-[0.6px] uppercase px-4 py-2 text-center border-r border-[#d9d9d6]">
                  Permission / Hak Akses
                </th>
                <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-2 text-center">
                  Akun
                </th>
              </tr>

              <tr className="bg-[#fafafa] border-b border-[#d9d9d6]">
                {["#", "Nama", "Username", "Loket / Unit"].map((h, i) => (
                  <th key={h} className={`font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-3 text-left whitespace-nowrap ${i === 3 ? "border-r border-[#d9d9d6]" : ""}`}>
                    {h}
                  </th>
                ))}
                {PERM_COLS.map((col, i) => (
                  <th key={col.key} className={`font-semibold text-[#185fa5] text-xs tracking-[0.6px] uppercase px-4 py-3 text-center whitespace-nowrap ${i === PERM_COLS.length - 1 ? "border-r border-[#d9d9d6]" : ""}`}>
                    {col.label}
                  </th>
                ))}
                <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-3 text-center whitespace-nowrap">
                  Aktif
                </th>
              </tr>
            </thead>

            <tbody>
              {rowsHalaman.length === 0 ? (
                <tr>
                  <td colSpan={totalKolom} className="text-center py-12 text-[#727782] text-sm">
                    Tidak ada pengguna yang sesuai filter.
                  </td>
                </tr>
              ) : (
                rowsHalaman.map((p, i) => {
                  const nomorUrut = (page - 1) * PAGE_SIZE + i + 1;
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
                      <td className="px-4 py-3 text-sm text-[#424751] font-mono whitespace-nowrap">{p.username}</td>
                      <td className="px-4 py-3 text-sm text-[#424751] whitespace-nowrap border-r border-[#f0f0ee]">{p.loket}</td>

                      {PERM_COLS.map((col, ci) => (
                        <td key={col.key} className={`px-4 py-3 text-center ${ci === PERM_COLS.length - 1 ? "border-r border-[#f0f0ee]" : ""}`}>
                          <div className="flex justify-center">
                            <Toggle value={!!p[col.key]} onChange={() => togglePerm(p.id, col.key)} />
                          </div>
                        </td>
                      ))}

                      <td className="px-4 py-3 text-center">
                        <div className="flex justify-center">
                          <Toggle value={p.aktif} onChange={() => toggleAktif(p.id)} />
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Paginasi */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-6 py-3 border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)]">
            <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
              Menampilkan {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, rowsTerfilter.length)} dari {rowsTerfilter.length} pengguna
            </span>
            <div className="flex items-center gap-1">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <IconChevronLeftOutline className="size-3.5" />
                Sebelumnya
              </button>
              <span className="px-3 py-1.5 text-xs font-semibold text-[#191c20]">{page} / {totalPages}</span>
              <button onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page === totalPages} className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
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
            ).length} dari {daftarPengguna.length} pengguna memiliki akses tambahan
          </span>
        </div>
      </div>
    </>
  );
}
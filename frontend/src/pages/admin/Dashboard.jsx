import { useState } from "react";
import { useNavigate } from "react-router-dom";
import StatusBadge from "@/components/shared/StatusBadge";
import {
  IconBuilding,
  IconCheckCircleFilled,
  IconWarningTriangle,
  IconRefresh,
  IconEye,
  IconSpinner,
  IconArchiveBox,
  IconShieldOutline,
} from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import useDashboardAdmin from "@/hooks/useDashboardAdmin";
import { tanggalLokal } from "@/utils/tanggal";

/**
 * Dashboard Verifikasi — halaman utama Super Admin. Route (di App.jsx):
 *   <Route path="/admin/dashboard" element={<AdminDashboard />} />
 *
 * `tanggalInput` vs `tanggalAktif` SENGAJA dipisah jadi 2 state, supaya
 * ganti-ganti tanggal di date picker TIDAK langsung memuat ulang data —
 * cocok dengan spesifikasi: ada 2 tombol terpisah, "Muat Data" (baru fetch
 * berdasarkan tanggal yang dipilih) dan "Refresh Data" (fetch ulang dengan
 * tanggal yang SAMA seperti sebelumnya, tidak peduli apa yang sedang
 * diketik di date picker tapi belum di-"Muat Data").
 *   - tanggalInput  -> apa yang sedang dipilih di date picker (bisa "belum
 *     final", user masih mikir-mikir sebelum klik Muat Data)
 *   - tanggalAktif  -> tanggal yang BENAR-BENAR dipakai untuk fetch data
 *     saat ini, cuma berubah saat tombol "Muat Data" diklik
 *
 * useDashboardAdmin(tanggalAktif) otomatis fetch ulang setiap tanggalAktif
 * berubah (lihat hooks/useDashboardAdmin.js) — jadi handleMuatData cukup
 * update tanggalAktif, tidak perlu manggil refresh() manual.
 */
export default function AdminDashboard() {
  const navigate = useNavigate();
  const [tanggalInput, setTanggalInput] = useState(() => tanggalLokal());
  const [tanggalAktif, setTanggalAktif] = useState(() => tanggalLokal());

  const { data, loading, error, refresh } = useDashboardAdmin(tanggalAktif);

  const items = data?.items ?? [];
  const ringkasan = data?.ringkasan ?? {
    total_loket: 0,
    sudah_submit: 0,
    belum_setor: 0,
    ada_selisih: 0,
  };
  const bridgeGagal = data?.bridge_gagal === true;
  const totalData = items.length;

  // "Muat Data" -> terapkan tanggal yang sedang dipilih di date picker.
  // Kalau tanggalnya SAMA dengan tanggalAktif sekarang, useEffect di dalam
  // hook tidak akan trigger ulang (dependency-nya tidak berubah) — jadi
  // sekalian panggil refresh() supaya tetap ada feedback "data dimuat
  // ulang" walau tanggalnya kebetulan tidak berubah.
  const handleMuatData = () => {
    if (tanggalInput === tanggalAktif) {
      refresh();
    } else {
      setTanggalAktif(tanggalInput);
    }
  };

  // "Refresh Data" -> muat ulang data untuk tanggalAktif yang SEDANG
  // dipakai, TIDAK peduli apa yang sedang diketik di date picker kalau
  // belum di-"Muat Data".
  const handleRefreshData = () => {
    refresh();
  };

  return (
    <>
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="flex flex-col gap-0.5">
          <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Dashboard</h1>
          <p className="text-[#424751] text-sm">Pantau status setoran seluruh loket SOPP secara real-time.</p>
        </div>
      </div>

      {/* Banner peringatan jika bridge SOPP gagal */}
      {bridgeGagal && (
        <div className="bg-[#fff8e1] border border-[#f59e0b]/40 rounded-lg px-5 py-4 flex items-start gap-3">
          <IconWarningTriangle className="size-5 text-[#b45309] shrink-0 mt-0.5" />
          <div className="flex flex-col gap-0.5">
            <span className="text-[#92400e] text-sm font-semibold">Koneksi Bridge SOPP Mengalami Kendala</span>
            <span className="text-[#b45309] text-xs leading-relaxed">
              Data pendapatan dari bridge SOPP tidak dapat dimuat secara sempurna untuk sebagian atau seluruh loket. Nilai pendapatan mungkin belum mencerminkan data aktual.
            </span>
          </div>
        </div>
      )}

      {error && (
        <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4 flex items-center justify-between">
          <span className="text-[#93000a] text-sm font-medium">Gagal memuat data dashboard.</span>
          <button
            onClick={refresh}
            className="border border-[#ba1a1a]/40 bg-white px-3 py-1.5 rounded text-xs font-medium text-[#93000a] hover:bg-[#ffdad6] transition-colors"
          >
            Coba lagi
          </button>
        </div>
      )}

      <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 w-full">
        <div className="flex flex-wrap gap-3 items-end">
          <div className="flex flex-col gap-1 min-w-[150px]">
            <label className="text-[#424751] text-xs font-medium">Pilih Tanggal</label>
            <input
              type="date"
              value={tanggalInput}
              onChange={(e) => setTanggalInput(e.target.value)}
              className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            />
          </div>

          <button
            onClick={handleMuatData}
            disabled={loading}
            className="bg-[#710014] px-4 py-2 rounded text-sm text-white font-medium hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            Muat Data
          </button>

          <button
            onClick={handleRefreshData}
            disabled={loading}
            className="border border-[#d9d9d6] bg-white flex gap-1.5 items-center px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <IconRefresh className="size-4" />
            <span>Refresh Data</span>
          </button>

        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 w-full">
        <RingkasanCard
          label="Total Loket (Global)"
          value={ringkasan.total_loket ?? 0}
          icon={<IconBuilding className="size-5" color="#185fa5" />}
          bgIcon="#e6f1fb"
        />
        <RingkasanCard
          label="Sudah Submit"
          value={ringkasan.sudah_submit ?? 0}
          icon={<IconCheckCircleFilled className="size-5" color="#006c49" />}
          bgIcon="#e1f5ee"
        />
        <RingkasanCard
          label="Belum Setor"
          value={ringkasan.belum_setor ?? 0}
          icon={<IconArchiveBox className="size-5" color="#92400e" />}
          bgIcon="#fef3c7"
        />
        <RingkasanCard
          label="Ada Selisih"
          value={ringkasan.ada_selisih ?? 0}
          icon={<IconWarningTriangle className="size-5" color="#93000a" />}
          bgIcon="#ffdad6"
        />
      </div>

      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3">
          <span className="font-bold text-[#191c20] text-base">Status Setoran per Loket</span>
        </div>

        {loading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-[#727782] text-sm">
            <IconSpinner className="size-5" />
            <span>Memuat data...</span>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px]">
              <thead>
                <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-4 py-3 whitespace-nowrap">No.</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-4 py-3 whitespace-nowrap">Nama Loket</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-4 py-3 whitespace-nowrap">Teller</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-4 py-3 whitespace-nowrap">Total Pendapatan</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-4 py-3 whitespace-nowrap">Total Setor</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-4 py-3 whitespace-nowrap">Selisih</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-center px-4 py-3 whitespace-nowrap">Status</th>
                  <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-center px-4 py-3 whitespace-nowrap">Detail</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-12 text-center text-sm text-[#727782]">
                      Tidak ada data setoran untuk tanggal ini.
                    </td>
                  </tr>
                ) : (
                  items.map((row, i) => {
                    const detailTeller = Array.isArray(row.detail_teller) ? row.detail_teller : [];
                    // [] .join() menghasilkan "" (bukan "-"), jadi fallback
                    // dicek SESUDAH join.
                    const tellerList = (Array.isArray(row.teller) ? row.teller.join(", ") : row.teller) || "-";

                    return (
                      <tr key={row.loket_id ?? i} className={`border-b border-[#d9d9d6] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"}`}>
                        <td className="px-4 py-3.5 text-sm text-[#424751]">{i + 1}</td>
                        <td className="px-4 py-3.5 text-sm text-[#191c20] font-medium whitespace-nowrap">{row.nama_loket}</td>
                        <td className="px-4 py-3.5 text-sm text-[#424751] whitespace-nowrap">
                          {tellerList}
                        </td>
                        <td className="px-4 py-3.5 text-sm text-[#191c20] text-right whitespace-nowrap">
                          {row.total_pendapatan_sopp != null ? formatRupiah(row.total_pendapatan_sopp) : "-"}
                        </td>
                        <td className="px-4 py-3.5 text-sm text-[#191c20] text-right whitespace-nowrap">
                          {formatRupiah(row.total_setor ?? 0)}
                        </td>
                        <td
                          className={`px-4 py-3.5 text-sm text-right font-semibold whitespace-nowrap ${row.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}`}
                        >
                          {row.selisih === null ? "—" : formatRupiah(row.selisih)}
                        </td>
                        <td className="px-4 py-3.5 text-center">
                          <div className="flex justify-center">
                            <StatusBadge status={row.status} />
                          </div>
                        </td>
                        <td className="px-4 py-3.5 text-center">
                          <div className="flex flex-col items-center gap-1.5">
                            {detailTeller.length > 0 ? (
                              detailTeller.map((dt, idx) => (
                                dt.setoran_id !== null ? (
                                  <button
                                    key={dt.setoran_id ?? idx}
                                    onClick={() => navigate(`/setoran/${dt.setoran_id}`)}
                                    title={detailTeller.length > 1 ? `Lihat setoran ${dt.nama || `Teller ${idx + 1}`}` : "Lihat detail setoran"}
                                    className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-80 transition-opacity whitespace-nowrap"
                                  >
                                    <IconEye className="size-3.5" />
                                    {detailTeller.length > 1 ? (dt.nama || `Setoran #${dt.setoran_id}`) : "Lihat"}
                                  </button>
                                ) : (
                                  <span key={idx} className="text-xs text-[#727782] italic">
                                    {dt.nama ? `${dt.nama}: Belum setor` : "Belum setor"}
                                  </span>
                                )
                              ))
                            ) : (
                              <span className="text-xs text-[#727782] italic">-</span>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        )}

        <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] px-6 py-3">
          <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
            Menampilkan 1-{totalData} dari {ringkasan.total_loket ?? totalData} Loket
          </span>
        </div>
      </div>
    </>
  );
}

function RingkasanCard({ label, value, icon, bgIcon }) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-5 flex gap-3 items-center">
      <div className="rounded-xl size-11 flex items-center justify-center shrink-0" style={{ backgroundColor: bgIcon }}>
        {icon}
      </div>
      <div className="flex flex-col">
        <span className="font-semibold text-[#727782] text-xs tracking-[0.6px] uppercase">{label}</span>
        <span className="font-bold text-[#191c20] text-2xl tracking-tight">{value}</span>
      </div>
    </div>
  );
}

function InfoBanner({ icon, title, text }) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 flex gap-3 items-start">
      <div className="shrink-0 mt-0.5">{icon}</div>
      <div className="flex flex-col gap-0.5">
        <span className="font-semibold text-[#191c20] text-sm">{title}</span>
        <span className="text-[#727782] text-xs leading-relaxed">{text}</span>
      </div>
    </div>
  );
}
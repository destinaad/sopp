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
  const [tanggalInput, setTanggalInput] = useState(new Date().toISOString().slice(0, 10));
  const [tanggalAktif, setTanggalAktif] = useState(new Date().toISOString().slice(0, 10));

  const { data, loading, error, refresh } = useDashboardAdmin(tanggalAktif);

  const loketStatusList = data?.loket_status ?? [];
  const ringkasan = data?.ringkasan ?? { totalLoket: 0, sudahSubmit: 0, belumSetor: 0, adaSelisih: 0 };
  const totalData = loketStatusList.length;

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
          value={ringkasan.totalLoket}
          icon={<IconBuilding className="size-5" color="#185fa5" />}
          bgIcon="#e6f1fb"
        />
        <RingkasanCard
          label="Sudah Submit"
          value={ringkasan.sudahSubmit}
          icon={<IconCheckCircleFilled className="size-5" color="#006c49" />}
          bgIcon="#e1f5ee"
        />
        <RingkasanCard
          label="Belum Setor"
          value={ringkasan.belumSetor}
          icon={<IconArchiveBox className="size-5" color="#92400e" />}
          bgIcon="#fef3c7"
        />
        <RingkasanCard
          label="Ada Selisih"
          value={ringkasan.adaSelisih}
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
                {loketStatusList.map((row, i) => (
                  <tr key={row.id} className={`border-b border-[#d9d9d6] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"}`}>
                    <td className="px-4 py-3.5 text-sm text-[#424751]">{row.no}</td>
                    <td className="px-4 py-3.5 text-sm text-[#191c20] font-medium whitespace-nowrap">{row.loket}</td>
                    <td className="px-4 py-3.5 text-sm text-[#424751] whitespace-nowrap">
                      {Array.isArray(row.teller) ? row.teller.join(", ") : row.teller} 
                    </td>
                    <td className="px-4 py-3.5 text-sm text-[#191c20] text-right whitespace-nowrap">{formatRupiah(row.totalPendapatan)}</td>
                    <td className="px-4 py-3.5 text-sm text-[#191c20] text-right whitespace-nowrap">{formatRupiah(row.totalSetor)}</td>
                    <td
                      className={`px-4 py-3.5 text-sm text-right font-semibold whitespace-nowrap ${row.selisih > 0 ? "text-[#93000a]" : "text-[#006c49]"}`}
                    >
                      {formatRupiah(row.selisih)}
                    </td>
                    <td className="px-4 py-3.5 text-center">
                      <div className="flex justify-center">
                        <StatusBadge status={row.status} />
                      </div>
                    </td>
                    <td className="px-4 py-3.5 text-center">
                      <button
                        onClick={() => navigate(`/setoran/${row.id}`)}
                        className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-80 transition-opacity"
                      >
                        <IconEye className="size-3.5" />
                        Lihat
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] px-6 py-3">
          <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
            Menampilkan 1-{totalData} dari {ringkasan.totalLoket} Loket
          </span>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 w-full">
        <InfoBanner
          icon={<IconShieldOutline className="size-5" color="#185fa5" />}
          title="Akurasi Settlement"
          text="Sistem otomatis mendeteksi selisih antara pendapatan SOPP dan nominal yang disetorkan teller, sehingga potensi ketidaksesuaian bisa langsung terlihat."
        />
        <InfoBanner
          icon={<IconArchiveBox className="size-5" color="#006c49" />}
          title="Integritas Data"
          text="Setiap perubahan data setoran tercatat dalam audit trail permanen, memastikan seluruh riwayat transaksi dapat ditelusuri kembali."
        />
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
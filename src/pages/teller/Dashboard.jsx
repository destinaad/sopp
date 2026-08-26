import { useState } from "react"; // state halaman untuk paginasi tabel aktivitas
import { useNavigate } from "react-router-dom"; // pindah halaman
import { useAuthStore } from "@/store/authStore"; // ambil data pengguna yang sedang login
import SummaryCards from "@/components/shared/SummaryCards"; // import komponen visual summary card
import AlertBanner from "@/components/shared/AlertBanner"; // import komponen visual spanduk notifikasi
import ActivityTable from "@/components/shared/ActivityTable"; // import komponen visual tabel aktivitas
import { IconSpinner } from "@/components/ui/Icon";
import { formatDateWithDay } from "@/utils/formatDate"; // import untuk mengubah format tanggal
import useDashboardTeller from "@/hooks/useDashboardTeller";

const PAGE_SIZE = 8; // disamakan dengan per_page Riwayat Setoran (lihat kontrak API §7.1)

/**
 * Halaman Dashboard khusus teller. Ini yang dipanggil dari routing di App.jsx,
 * dibungkus PageLayout, contoh:
 *
 *   <Route path="/teller/dashboard" element={<Dashboard />} />
 *
 * Data sekarang datang dari useDashboardTeller() (hook -> api/teller.js ->
 * getDashboard()). Sumber data di dalam getDashboard() masih dummy, tapi
 * BENTUK datanya sudah mengikuti kontrak API asli (snake_case, field
 * pendapatan_sopp/status_setoran/dst) — jadi begitu backend siap, cukup
 * ganti isi getDashboard() jadi panggilan axios sungguhan, halaman ini
 * TIDAK PERLU diubah lagi.
 */
export default function Dashboard() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const [page, setPage] = useState(1);

  const { data, loading, error, refresh } = useDashboardTeller();

  // Map field API (snake_case) -> bentuk yang diharapkan SummaryCards (camelCase).
  // Dilakukan di sini (bukan di dalam SummaryCards.jsx) supaya komponen
  // presentasional itu tetap netral, tidak perlu tahu soal bentuk API.
  const summary = data
    ? {
        pendapatanHariIni: data.pendapatan_sopp,
        pendapatanStatus: data.pendapatan_sopp_status, // null | "gagal_dimuat"
        persenVsKemarin: data.persentase_vs_kemarin, // bisa null
        statusSetoran: data.status_setoran, // "draft" | "submitted" | "belum_input"
        selisih: data.selisih,
      }
    : null;

  const riwayatTerakhir = data?.riwayat_terakhir ?? [];
  const totalPages = Math.max(1, Math.ceil(riwayatTerakhir.length / PAGE_SIZE));
  const rowsHalaman = riwayatTerakhir.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  if (!data) {
    return (
      <div className="flex items-center justify-center gap-2 py-24 text-[#727782] text-sm">
        <IconSpinner className="size-5" />
        <span>Memuat dashboard...</span>
      </div>
    );
  }

  if (error && !data) {
    return (
      <div className="bg-white rounded-lg border border-[#ffdad6] p-6 text-center">
        <p className="text-[#93000a] text-sm font-medium mb-3">Gagal memuat data dashboard.</p>
        <button
          onClick={refresh}
          className="border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
        >
          Coba lagi
        </button>
      </div>
    );
  }

  return (
    <>
      <div className="flex flex-col gap-1">
        <h1 className="font-bold text-[#191c20] text-3xl tracking-tight" style={{ fontFamily: "Inter, sans-serif" }}>
          Selamat datang, {user.name}
        </h1>
        <p className="text-[#424751] text-base">
          Loket: {user.loket} | {formatDateWithDay(new Date())}
        </p>
      </div>

      <SummaryCards summary={summary} />

      {summary?.statusSetoran === "belum_input" && (
        <AlertBanner onInputClick={() => navigate("/teller/input")} />
      )}

      <ActivityTable
        rows={rowsHalaman}
        totalEntri={riwayatTerakhir.length}
        page={page}
        totalPages={totalPages}
        onPageChange={setPage}
        onLihatSemua={() => navigate("/teller/riwayat")}
      />
    </>
  );
}
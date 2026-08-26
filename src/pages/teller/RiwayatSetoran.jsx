import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import FilterBar from "@/components/shared/FilterBar";
import RiwayatTable from "@/components/shared/RiwayatTable";
import RiwayatSummaryCards from "@/components/shared/RiwayatSummaryCards";
import { IconDownload } from "@/components/ui/Icon";
import useRiwayatSetoran from "@/hooks/useRiwayatSetoran";

export default function RiwayatSetoran() {
  const navigate = useNavigate();

  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [statusKeuangan, setStatusKeuangan] = useState("semua");
  const [statusWorkflow, setStatusWorkflow] = useState("semua");
  const [currentPage, setCurrentPage] = useState(1);

  const { data, loading, error, refresh, setFilters } = useRiwayatSetoran({ page: currentPage });

  // Setiap kali salah satu filter (atau halaman) berubah, kirim ulang
  // ke hook sebagai parameter request — BUKAN lagi difilter manual di
  // sisi FE lewat .filter(). Ini penting karena kontrak API bilang BE
  // yang bertanggung jawab menyaring & mem-paginasi data, FE cukup
  // kirim filter sebagai query parameter.
  useEffect(() => {
    setFilters({
      tanggal_mulai: dari || undefined,
      tanggal_akhir: sampai || undefined,
      status_keuangan: statusKeuangan === "semua" ? undefined : statusKeuangan,
      status_workflow: statusWorkflow === "semua" ? undefined : statusWorkflow,
      page: currentPage,
    });
  }, [dari, sampai, statusKeuangan, statusWorkflow, currentPage, setFilters]);

  const rows = data.items ?? [];
  const meta = data.meta ?? {};

  const handleReset = () => {
    setDari("");
    setSampai("");
    setStatusKeuangan("semua");
    setStatusWorkflow("semua");
    setCurrentPage(1);
  };

  const handleLihatDetail = (row) => {
    if (row.statusWorkflow === "draft") {
      navigate("/teller/input", { state: { draftData: row }});
    } else {
      navigate(`/setoran/${row.id}`)
    }
  };

  const handleTerapkan = () => setCurrentPage(1);

  return (
    <>
    {error && (
        <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4 flex items-center justify-between">
          <span className="text-[#93000a] text-sm font-medium">Gagal memuat data riwayat setoran.</span>
          <button
            onClick={() => refresh()}
            className="border border-[#ba1a1a]/40 bg-white px-3 py-1.5 rounded text-xs font-medium text-[#93000a] hover:bg-[#ffdad6] transition-colors"
          >
            Coba lagi
          </button>
        </div>
      )}
      
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="flex flex-col gap-0.5">
          <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Riwayat Setoran</h1>
          <p className="text-[#424751] text-sm">Kelola dan tinjau seluruh rekaman setoran harian unit kerja.</p>
        </div>

        <button className="bg-[#710014] flex gap-2 items-center px-5 py-3 rounded text-white text-sm font-medium hover:bg-[#5a000f] transition-colors shrink-0 shadow-sm">
          <IconDownload color="white" />
          <span>Ekspor Laporan (Excel)</span>
        </button>
      </div>

      <FilterBar
        dari={dari}
        sampai={sampai}
        statusKeuangan={statusKeuangan}
        statusWorkflow={statusWorkflow}
        onDariChange={setDari}
        onSampaiChange={setSampai}
        onStatusKeuanganChange={setStatusKeuangan}
        onStatusWorkflowChange={setStatusWorkflow}
        onReset={handleReset}
        onTerapkan={handleTerapkan}
      />

      <RiwayatTable
        rows={rows}
        totalData={meta.total_items ?? 0}
        currentPage={meta.current_page ?? currentPage}
        totalPages={meta.total_pages ?? 1}
        onPageChange={setCurrentPage}
        onLihatDetail={(row) => handleLihatDetail(row)}
      />

      <RiwayatSummaryCards ringkasan={data.ringkasan ?? {}} />
    </>
  );
}
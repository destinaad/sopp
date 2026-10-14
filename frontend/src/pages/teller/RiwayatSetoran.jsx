import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import FilterBar from "@/components/shared/FilterBar";
import RiwayatTable from "@/components/shared/RiwayatTable";
import RiwayatSummaryCards from "@/components/shared/RiwayatSummaryCards";
import { IconDownload } from "@/components/ui/Icon";
import useRiwayatSetoran from "@/hooks/useRiwayatSetoran";
import { exportRiwayat, getLokets } from "@/api/teller";
import { useAuthStore } from "@/store/authStore";

export default function RiwayatSetoran() {
  const navigate = useNavigate();

  const user = useAuthStore((s) => s.user);
  const bisaLintasLoket = Boolean(user?.bisa_edit_setoran_semua_loket);
  const [daftarLoket, setDaftarLoket] = useState([]);
  const [loketId, setLoketId] = useState("");

  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [statusKeuangan, setStatusKeuangan] = useState("semua");
  const [statusWorkflow, setStatusWorkflow] = useState("semua");
  const [currentPage, setCurrentPage] = useState(1);
  const [loadingExport, setLoadingExport] = useState(false);

  const { data, loading, error, refresh, setFilters, filtersUntukExport } = useRiwayatSetoran({ page: currentPage });

  useEffect(() => {
    setFilters({
      tanggal_mulai: dari || undefined,
      tanggal_akhir: sampai || undefined,
      status_keuangan: statusKeuangan === "semua" ? undefined : statusKeuangan,
      status_workflow: statusWorkflow === "semua" ? undefined : statusWorkflow,
      loket_id: loketId ? Number(loketId) : undefined,
      page: currentPage,
    });
  }, [dari, sampai, statusKeuangan, statusWorkflow, loketId, currentPage, setFilters]);

  useEffect(() => {
    if (!bisaLintasLoket) return;
    getLokets()
      .then((res) => {
        if (res?.status === "ok") setDaftarLoket(res.data ?? []);
        else if (Array.isArray(res)) setDaftarLoket(res);
      })
      .catch(() => {});
  }, [bisaLintasLoket]);

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
    if (row.status_workflow === "draft") {
      navigate("/teller/input", { state: { tanggalDraft: row.tanggal_transaksi } });
    } else {
      navigate(`/setoran/${row.id}`);
    }
  };

  const handleTerapkan = () => setCurrentPage(1);

  const handleExport = async () => {
    setLoadingExport(true);
    try {
      const res = await exportRiwayat(filtersUntukExport);

      // Buat link download dari blob response
      const url = window.URL.createObjectURL(
        new Blob([res.data], {
          type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        })
      );
      const link = document.createElement("a");
      link.href = url;

      // Nama file default — pakai tanggal hari ini
      const namaFile = `riwayat-setoran-${new Date().toISOString().slice(0, 10)}.xlsx`;
      link.setAttribute("download", namaFile);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      const status = err?.response?.status;
      if (status === 429) {
        alert("Terlalu banyak permintaan ekspor. Tunggu sebentar lalu coba lagi.");
      } else if (status === 403) {
        alert("Tidak memiliki akses untuk mengekspor data ini.");
      } else {
        alert("Gagal mengekspor laporan. Coba lagi.");
      }
    } finally {
      setLoadingExport(false);
    }
  };

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

        <div className="flex flex-col items-end gap-1.5">
          <button
            onClick={handleExport}
            disabled={loadingExport}
            className="bg-[#710014] flex gap-2 items-center px-5 py-3 rounded text-white text-sm font-medium hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors shrink-0 shadow-sm"
          >
            <IconDownload color="white" />
            <span>{loadingExport ? "Mengekspor..." : "Ekspor Laporan (Excel)"}</span>
          </button>

          {/* Peringatan muncul hanya kalau filter tanggal kosong —
              server otomatis membatasi hasil ke 30 hari terakhir
              kalau tanggal_mulai tidak dikirim */}
          {!dari && (
            <p className="text-[#727782] text-xs">
              Filter tanggal kosong — hasil unduhan otomatis dibatasi 30 hari terakhir.
            </p>
          )}
        </div>
      </div>

      {bisaLintasLoket && (
        <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 w-full flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1 min-w-[220px]">
            <label className="text-[#424751] text-xs font-medium">Loket</label>
            <select
              value={loketId}
              onChange={(e) => {
                setLoketId(e.target.value);
                setCurrentPage(1);
              }}
              className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            >
              <option value="">{user?.loket_id ? "Setoran saya" : "Pilih loket..."}</option>
              {daftarLoket.map((l) => {
                const id = l.id ?? l.loket_id;
                const nama = l.nama ?? l.nama_loket;
                return (
                  <option key={id} value={id}>
                    {nama}
                  </option>
                );
              })}
            </select>
          </div>
          {loketId && (
            <p className="text-[#727782] text-xs pb-2.5">
              Menampilkan setoran yang sudah disubmit oleh semua teller di loket ini.
            </p>
          )}
          {!loketId && !user?.loket_id && (
            <p className="text-[#727782] text-xs pb-2.5">Pilih loket untuk melihat riwayat setorannya.</p>
          )}
        </div>
      )}

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
        onHapusSuccess={() => refresh()}
        loading={loading}
      />

      <RiwayatSummaryCards ringkasan={data.ringkasan ?? {}} />
    </>
  );
}
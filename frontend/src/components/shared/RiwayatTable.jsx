import { useState } from "react";
import StatusBadge from "@/components/shared/StatusBadge";
import { IconEye, IconTrash, IconChevronLeftOutline, IconChevronRightOutline } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { formatTanggalPanjang } from "@/utils/formatDate";
import { deleteRiwayat } from "@/api/teller";

const colHead = "font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-4 whitespace-nowrap";

export default function RiwayatTable({ rows, totalData, currentPage, totalPages, onPageChange, onLihatDetail, onHapusSuccess, loading = false }) {
  const [modalHapus, setModalHapus] = useState(null);
  const [alasan, setAlasan] = useState("");
  const [loadingHapus, setLoadingHapus] = useState(false);
  const [errorHapus, setErrorHapus] = useState("");

  const handleKonfirmasiHapus = async () => {
    if (!modalHapus) return;
    const isSubmitted = modalHapus.status_workflow === "submitted";

    if (isSubmitted && !alasan.trim()) {
      setErrorHapus("Alasan wajib diisi untuk menghapus setoran yang sudah submitted.");
      return;
    }

    setLoadingHapus(true);
    setErrorHapus("");
    try {
      await deleteRiwayat(modalHapus.id, isSubmitted ? alasan.trim() : null);
      setModalHapus(null);
      setAlasan("");
      onHapusSuccess?.();
    } catch (err) {
      const data = err?.response?.data;
      setErrorHapus(data?.message || "Gagal menghapus. Coba lagi.");
    } finally {
      setLoadingHapus(false);
    }
  };

  // Mode lintas-loket: BE mengirim nama_teller di tiap baris (dan tidak lagi nama_loket).
  const adaTeller = rows.some((r) => r.nama_teller);

  return (
    <>
      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px]">
            <thead>
              <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
                <th className={`${colHead} text-left`}>Tanggal</th>
                {adaTeller && <th className={`${colHead} text-left`}>Teller</th>}
                <th className={`${colHead} text-right`}>Total Pendapatan</th>
                <th className={`${colHead} text-right`}>Transfer</th>
                <th className={`${colHead} text-right`}>QRIS</th>
                <th className={`${colHead} text-right`}>Tunai</th>
                <th className={`${colHead} text-right`}>KPAY</th>
                <th className={`${colHead} text-right`}>Selisih</th>
                <th className={`${colHead} text-center`}>Status Workflow</th>
                <th className={`${colHead} text-center`}>Aksi</th>
              </tr>
            </thead>
            <tbody
              className={`transition-opacity duration-150 ${loading ? "opacity-40" : "opacity-100 animate-fadeIn"}`}
            >
              {loading && rows.length === 0
                ? Array.from({ length: 5 }).map((_, i) => (
                    <tr key={`skeleton-${i}`} className="border-b border-[#d9d9d6]">
                      {Array.from({ length: 9 }).map((_, j) => (
                        <td key={j} className="px-4 py-4">
                          <div className="h-4 bg-[#ededf4] rounded animate-pulse" />
                        </td>
                      ))}
                    </tr>
                  ))
                : rows.map((row, i) => (
                    <tr
                      key={row.id}
                      className={`border-b border-[#d9d9d6] transition-colors hover:bg-[#fafafa] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"}`}
                    >
                      <td className="px-4 py-4 text-sm text-[#191c20] font-medium whitespace-nowrap">
                        {formatTanggalPanjang(row.tanggal_transaksi)}
                      </td>
                      {adaTeller && (
                        <td className="px-4 py-4 text-sm text-[#191c20] whitespace-nowrap">{row.nama_teller ?? "-"}</td>
                      )}
                      <td className="px-4 py-4 text-sm text-[#191c20] text-right whitespace-nowrap">
                        {formatRupiah(row.total_pendapatan)}
                      </td>
                      <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">
                        {formatRupiah(row.nominal_transfer)}
                      </td>
                      <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">
                        {formatRupiah(row.nominal_qris)}
                      </td>
                      <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">
                        {formatRupiah(row.nominal_tunai)}
                      </td>
                      <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">
                        {formatRupiah(row.nominal_kpay)}
                      </td>
                      <td
                        className={`px-4 py-4 text-sm font-semibold text-right whitespace-nowrap ${
                          row.selisih === 0 ? "text-[#006c49]" : "text-[#710014]"
                        }`}
                      >
                        {row.selisih === null ? "—" : formatRupiah(row.selisih)}
                      </td>
                      <td className="px-4 py-4 text-center">
                        <StatusBadge status={row.status_workflow} />
                      </td>
                      <td className="px-4 py-4 text-center">
                        <div className="flex items-center justify-center gap-2">
                          <button
                            onClick={() => onLihatDetail?.(row)}
                            aria-label="Lihat detail"
                            className="inline-flex items-center justify-center border border-[#c2c6d2] rounded-lg size-9 text-[#424751] hover:bg-[#f7f7f6] hover:border-[#710014] hover:text-[#710014] transition-colors"
                          >
                            <IconEye />
                          </button>
                          <button
                            disabled={!row.bisa_dihapus}
                            title={row.bisa_dihapus ? "Hapus" : "Anda tidak memiliki izin menghapus setoran ini"}
                            onClick={() => {
                              if (!row.bisa_dihapus) return;
                              setModalHapus({ id: row.id, status_workflow: row.status_workflow });
                              setAlasan("");
                              setErrorHapus("");
                            }}
                            aria-label="Hapus"
                            className={`inline-flex items-center justify-center border border-[#c2c6d2] rounded-lg size-9 text-[#424751] transition-colors ${
                              row.bisa_dihapus
                                ? "hover:bg-[#ffdad6] hover:border-[#ba1a1a] hover:text-[#93000a]"
                                : "opacity-40 cursor-not-allowed"
                            }`}
                          >
                            <IconTrash />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
            </tbody>
          </table>
        </div>

        <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 px-6 py-4">
          <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
            Menampilkan {rows.length} dari {totalData} data setoran
          </span>

          <div className="flex items-center gap-1">
            <button
              onClick={() => onPageChange(Math.max(1, currentPage - 1))}
              disabled={currentPage === 1}
              className="size-8 flex items-center justify-center rounded border border-[#c2c6d2] text-[#424751] disabled:opacity-40 hover:bg-[#f7f7f6] transition-colors"
              aria-label="Halaman sebelumnya"
            >
              <IconChevronLeftOutline />
            </button>

            {Array.from({ length: totalPages }, (_, i) => i + 1)
              .filter((p) => p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1)
              .reduce((acc, p, idx, arr) => {
                if (idx > 0 && p - arr[idx - 1] > 1) acc.push("...");
                acc.push(p);
                return acc;
              }, [])
              .map((p, idx) =>
                p === "..." ? (
                  <span key={`ellipsis-${idx}`} className="px-1 text-[#727782] text-xs">...</span>
                ) : (
                  <button
                    key={p}
                    onClick={() => onPageChange(p)}
                    className={`size-8 flex items-center justify-center rounded text-xs font-semibold transition-colors ${
                      currentPage === p
                        ? "bg-[#710014] text-white border border-[#710014]"
                        : "border border-[#c2c6d2] text-[#424751] hover:bg-[#f7f7f6]"
                    }`}
                  >
                    {p}
                  </button>
                )
              )}

            <button
              onClick={() => onPageChange(Math.min(totalPages, currentPage + 1))}
              disabled={currentPage === totalPages}
              className="size-8 flex items-center justify-center rounded border border-[#c2c6d2] text-[#424751] disabled:opacity-40 hover:bg-[#f7f7f6] transition-colors"
              aria-label="Halaman berikutnya"
            >
              <IconChevronRightOutline />
            </button>
          </div>
        </div>
      </div>

      {/* Modal konfirmasi hapus */}
      {modalHapus && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
          <div className="bg-white rounded-lg border border-[#d9d9d6] shadow-lg w-full max-w-md p-6 flex flex-col gap-4">
            <h2 className="font-semibold text-[#191c20] text-lg">Hapus Setoran</h2>
            <p className="text-[#424751] text-sm">
              {modalHapus.status_workflow === "submitted"
                ? "Setoran ini sudah disubmit. Isi alasan penghapusan sebelum melanjutkan."
                : "Yakin ingin menghapus draft setoran ini? Tindakan ini tidak bisa dibatalkan."}
            </p>

            {modalHapus.status_workflow === "submitted" && (
              <div className="flex flex-col gap-1.5">
                <label className="font-semibold text-[#424751] text-sm">Alasan Penghapusan</label>
                <textarea
                  value={alasan}
                  onChange={(e) => setAlasan(e.target.value)}
                  maxLength={500}
                  rows={3}
                  placeholder="Tuliskan alasan penghapusan..."
                  className="w-full border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] placeholder-[#9ca3af] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 resize-none"
                />
                <span className="text-[#9ca3af] text-xs text-right">{alasan.length}/500</span>
              </div>
            )}

            {errorHapus && (
              <p className="text-[#93000a] text-sm bg-[#ffdad6] px-3 py-2 rounded">{errorHapus}</p>
            )}

            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setModalHapus(null)}
                disabled={loadingHapus}
                className="border border-[#d9d9d6] bg-white px-5 py-2.5 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors disabled:opacity-50"
              >
                Batal
              </button>
              <button
                onClick={handleKonfirmasiHapus}
                disabled={loadingHapus}
                className="bg-[#93000a] text-white px-5 py-2.5 rounded text-sm font-medium hover:bg-[#710014] transition-colors disabled:opacity-50"
              >
                {loadingHapus ? "Menghapus..." : "Ya, Hapus"}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
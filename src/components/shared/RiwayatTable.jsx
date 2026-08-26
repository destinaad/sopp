import StatusBadge from "@/components/shared/StatusBadge";
import { IconEye, IconChevronLeftOutline, IconChevronRightOutline } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { formatTanggalPanjang } from "@/utils/formatDate";

const colHead = "font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-4 py-4 whitespace-nowrap";

export default function RiwayatTable({ rows, totalData, currentPage, totalPages, onPageChange, onLihatDetail }) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[900px]">
          <thead>
            <tr className="bg-[#f7f7f6] border-b border-[#d9d9d6]">
              <th className={`${colHead} text-left`}>Tanggal</th>
              <th className={`${colHead} text-right`}>Total Pendapatan</th>
              <th className={`${colHead} text-right`}>Transfer</th>
              <th className={`${colHead} text-right`}>QRIS</th>
              <th className={`${colHead} text-right`}>Tunai</th>
              <th className={`${colHead} text-right`}>KPAY</th>
              <th className={`${colHead} text-right`}>Selisih</th>
              <th className={`${colHead} text-center`}>Status Workflow</th>
              <th className={`${colHead} text-center`}>Detail</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr
                key={row.id}
                className={`border-b border-[#d9d9d6] transition-colors hover:bg-[#fafafa] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"}`}
              >
                <td className="px-4 py-4 text-sm text-[#191c20] font-medium whitespace-nowrap">
                  {formatTanggalPanjang(row.tanggal)}
                </td>
                <td className="px-4 py-4 text-sm text-[#191c20] text-right whitespace-nowrap">
                  {formatRupiah(row.totalPendapatan)}
                </td>
                <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">{formatRupiah(row.transfer)}</td>
                <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">{formatRupiah(row.qris)}</td>
                <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">{formatRupiah(row.tunai)}</td>
                <td className="px-4 py-4 text-sm text-[#424751] text-right whitespace-nowrap">{formatRupiah(row.kpay)}</td>
                <td
                  className={`px-4 py-4 text-sm font-semibold text-right whitespace-nowrap ${
                    row.selisih > 0 ? "text-[#710014]" : "text-[#006c49]"
                  }`}
                >
                  {formatRupiah(row.selisih)}
                </td>
                <td className="px-4 py-4 text-center">
                  <div className="flex flex-col gap-1 items-center">
                    <StatusBadge status={row.statusWorkflow} />
                  </div>
                </td>
                <td className="px-4 py-4 text-center">
                  <button
                    onClick={() => onLihatDetail?.(row)}
                    aria-label="Lihat detail"
                    className="inline-flex items-center justify-center border border-[#c2c6d2] rounded-lg size-9 text-[#424751] hover:bg-[#f7f7f6] hover:border-[#710014] hover:text-[#710014] transition-colors"
                  >
                    <IconEye />
                  </button>
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

          {[1, 2, 3].map((p) => (
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
          ))}

          <span className="px-1 text-[#727782] text-xs">...</span>

          <button
            onClick={() => onPageChange(totalPages)}
            className={`size-8 flex items-center justify-center rounded text-xs font-semibold transition-colors ${
              currentPage === totalPages
                ? "bg-[#710014] text-white border border-[#710014]"
                : "border border-[#c2c6d2] text-[#424751] hover:bg-[#f7f7f6]"
            }`}
          >
            {totalPages}
          </button>

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
  );
}
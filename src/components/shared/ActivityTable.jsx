import StatusBadge from "@/components/shared/StatusBadge";
import { IconExternalLink, IconChevronLeft, IconChevronRight } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { formatDate } from "@/utils/formatDate";

/**
 * Tabel "Aktivitas Setoran Terakhir" di dashboard teller.
 * `rows` yang diterima di sini SUDAH terpotong per halaman (lihat Dashboard.jsx) —
 * komponen ini murni presentasional, tidak melakukan slice sendiri.
 * `totalEntri` = jumlah total riwayat teller ini (dipakai untuk teks "dari Y entri"),
 * BUKAN jumlah baris yang sedang tampil.
 */
export default function ActivityTable({ rows, totalEntri, page, totalPages, onPageChange, onLihatSemua }) {
  return (
    <div className="relative bg-white rounded-lg w-full overflow-hidden">
      <div aria-hidden className="absolute border border-[#d9d9d6] border-solid inset-0 pointer-events-none rounded-lg" />

      <div className="relative border-b border-[#c2c6d2] flex items-center justify-between px-6 py-4">
        <span className="font-semibold text-[#191c20] text-xl">Aktivitas Setoran Terakhir</span>
        <button
          className="flex gap-1 items-center text-[#710014] text-sm font-medium hover:opacity-80 transition-opacity"
          onClick={onLihatSemua}
        >
          <span>Lihat Semua</span>
          <IconExternalLink />
        </button>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[600px]">
          <thead>
            <tr className="bg-[#efefed] border-b border-[#c2c6d2]">
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-6 py-4 whitespace-nowrap">TANGGAL</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-6 py-4 whitespace-nowrap">TOTAL PENDAPATAN</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-6 py-4 whitespace-nowrap">TOTAL SETOR</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-6 py-4 whitespace-nowrap">SELISIH</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-6 py-4 whitespace-nowrap">STATUS</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="text-center py-10 text-[#727782] text-sm">
                  Belum ada aktivitas setoran.
                </td>
              </tr>
            ) : (
              rows.map((row, i) => {
                const selisihColor = row.selisih === 0 ? "#006c49" : "#ba1a1a"; // hijau jika balance (selisih 0), merah jika ada selisih
                return (
                  <tr key={row.tanggal} className={`border-t border-[#c2c6d2] ${i % 2 === 1 ? "bg-[#f7f7f6]" : ""}`}>
                    <td className="font-medium text-[#191c20] text-sm px-6 py-5 whitespace-nowrap">{formatDate(row.tanggal)}</td>
                    <td className="text-[#191c20] text-sm text-right px-6 py-5 whitespace-nowrap">{formatRupiah(row.totalPendapatan)}</td>
                    <td className="text-[#191c20] text-sm text-right px-6 py-5 whitespace-nowrap">{formatRupiah(row.totalSetor)}</td>
                    <td className="text-sm text-right px-6 py-5 whitespace-nowrap font-semibold" style={{ color: selisihColor }}>
                      {formatRupiah(row.selisih)}
                    </td>
                    <td className="px-6 py-4">
                      <StatusBadge status={row.statusWorkflow} />
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      <div className="bg-[rgba(242,243,250,0.5)] border-t border-[#c2c6d2] flex items-center justify-between px-6 py-4">
        <span className="font-semibold text-[#424751] text-xs tracking-[0.6px]">
          Menampilkan {rows.length} dari {totalEntri} entri terakhir
        </span>
        <div className="flex gap-1">
          <button
            onClick={() => onPageChange(Math.max(1, page - 1))}
            disabled={page === 1}
            className="border border-[#c2c6d2] flex items-center justify-center px-2 py-2 rounded-sm hover:bg-[#f7f7f6] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent transition-colors"
            aria-label="Sebelumnya"
          >
            <IconChevronLeft />
          </button>
          <button
            onClick={() => onPageChange(Math.min(totalPages, page + 1))}
            disabled={page === totalPages}
            className="border border-[#c2c6d2] flex items-center justify-center px-2 py-2 rounded-sm hover:bg-[#f7f7f6] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent transition-colors"
            aria-label="Berikutnya"
          >
            <IconChevronRight />
          </button>
        </div>
      </div>
    </div>
  );
}
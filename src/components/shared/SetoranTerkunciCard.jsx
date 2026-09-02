import { useNavigate } from "react-router-dom";
import StatusBadge from "@/components/shared/StatusBadge";
import { IconLock } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { formatDateWithDay } from "@/utils/formatDate";

/**
 * Ditampilkan menggantikan form Input Setoran ketika tanggal yang dipilih
 * teller SUDAH punya entry setoran tercatat (lihat getStatusSetoranTanggal
 * di mocks/setoran.js). Sengaja form-nya tidak dirender sama sekali (bukan
 * cuma di-disable) supaya teller tidak salah kira bisa mengedit dari sini —
 * kalau memang ada selisih yang mau dilunasi, harus lewat card "Pelunasan
 * Setoran Lama" (tombol di bawah).
 */
export default function SetoranTerkunciCard({ tanggal, data, onBukaPelunasan }) {
  const navigate = useNavigate();

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
      <div className="bg-[#FAEEDA] border-b border-[#854F0B33] px-5 py-4 flex items-start gap-3">
        <IconLock className="shrink-0 size-5 text-[#854F0B] mt-0.5" />
        <div>
          <p className="text-[#854F0B] text-sm font-semibold">
            Setoran tanggal {formatDateWithDay(new Date(`${tanggal}T00:00:00`))} sudah tercatat
          </p>
          <p className="text-[#854F0B] text-sm mt-0.5" style={{ opacity: 0.9 }}>
            Form input dikunci untuk mencegah entry ganda. Jika ada selisih yang perlu dilunasi,
            gunakan menu <span className="font-medium">Pelunasan Setoran Lama</span> di bawah.
          </p>
        </div>
      </div>

      <div className="p-5 flex flex-col gap-4">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div className="flex flex-col gap-0.5">
            <span className="text-[#727782] text-xs font-semibold uppercase tracking-[0.6px]">Total Setoran</span>
            <span className="text-[#191c20] text-sm font-semibold">{formatRupiah(data?.totalPendapatan ?? 0)}</span>
          </div>
          <div className="flex flex-col gap-0.5">
            <span className="text-[#727782] text-xs font-semibold uppercase tracking-[0.6px]">Selisih</span>
            <span className={`text-sm font-semibold ${data?.selisih > 0 ? "text-[#710014]" : "text-[#006c49]"}`}>
              {formatRupiah(data?.selisih ?? 0)}
            </span>
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-[#727782] text-xs font-semibold uppercase tracking-[0.6px]">Status Workflow</span>
            <StatusBadge status={data?.statusWorkflow} />
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-[#727782] text-xs font-semibold uppercase tracking-[0.6px]">Status Keuangan</span>
            <StatusBadge status={data?.statusKeuangan} />
          </div>
        </div>

        <div className="flex flex-wrap gap-3 pt-1">
          {data?.id && (
            <button
              onClick={() => navigate(`/setoran/${data.id}`)}
              className="border border-[#d9d9d6] bg-white px-4 py-2.5 rounded text-sm font-medium text-[#424751] hover:bg-[#f7f7f6] transition-colors"
            >
              Lihat Detail Setoran
            </button>
          )}
          {data?.selisih > 0 && (
            <button
              onClick={onBukaPelunasan}
              className="bg-[#710014] px-4 py-2.5 rounded text-sm font-medium text-white hover:bg-[#5a000f] transition-colors"
            >
              Lunasi Selisih
            </button>
          )}
        </div>
      </div>
    </div>
  );
}